<?php

namespace ParticleAcademy\Fms\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ParticleAcademy\Fms\Events\FeatureOverageRecorded;
use ParticleAcademy\Fms\Models\FeatureUsage;
use ParticleAcademy\Fms\Quota;

/**
 * Fms service — feature entitlement and metering for a billing subscription.
 *
 * The subscription-scoped half of this package. `FeatureManager` answers from
 * the registry, groups and config with no storage of its own; this one walks
 * subscription → product → `product_feature_configs` pivot and meters against
 * the `feature_usages` table.
 *
 * ## `can()` answers ENTITLEMENT, not quota — changed in 0.11.0
 *
 * `can()` used to mean "entitled AND there is quota left" for a resource
 * feature, while `FeatureManager::canAccess()` meant "entitled" for the same
 * feature defined in the registry. One question, two answers, decided by which
 * layer the plan happened to be modelled in.
 *
 *   - `can()` / `isEntitled()` — is the subject entitled to this feature.
 *   - `canConsume()` — entitled AND this amount fits. What `can()` used to
 *     answer, and the one-word migration for anyone who was using `can()` as a
 *     consumption gate.
 *   - `tryIncrement()` — the only one safe to gate an actual write with,
 *     because it takes the row lock. `canConsume()` is a READ: between it and
 *     the write, another request can spend the last unit.
 *
 * ## Configuration
 *
 * Three model classes, all from `config/fms.php`: `subscription_model`,
 * `user_model` and `product_feature_model`. Two of them were hard-coded to one
 * application's classes until 0.10.0, which meant `can()` denied everything
 * everywhere else.
 *
 * The subscription model needs an `active()` scope, a `product()` method and a
 * `featureUsages()` relationship; the product needs `productFeatures()`.
 */
class Fms
{
    protected ?string $lastError = null;

    /**
     * Is the resolved scope ENTITLED to this feature?
     *
     * Quota-blind, from 0.11.0. A metered feature whose allowance is exhausted
     * is still entitled — the customer is still paying for it, and hiding it at
     * the moment they are spending most is the opposite of useful.
     *
     * **If you were using this to guard consumption, move to `canConsume()`** —
     * or better, to `tryIncrement()`, which is the only variant that is not
     * racy. This method now returns true where it used to return false at zero
     * remaining, and nothing warns: that is the one thing to check on upgrade.
     *
     * When it returns false and a `$message` is given, the message is recorded
     * as `lastError()` for workflow consumption.
     */
    public function can(string $featureKey, mixed $scope = null, ?string $message = null): bool
    {
        $this->lastError = null;

        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return $this->deny($message);
        }

        [, $feature, $config] = $resolved;

        // Boolean and resource features answer the same question here, and it
        // is the pivot's `enabled` flag in both cases. Before 0.11.0 a resource
        // feature ignored `enabled` entirely and looked only at remaining quota,
        // so a pivot row with `enabled = false` and an included quantity was
        // treated as ON — while the Node and Python twins have always required
        // `enabled` on a resource grant.
        if (! Quota::entitled(
            (bool) $config->enabled,
            (string) $feature->type,
            $this->includedQuantity($config),
            0,
        )) {
            return $this->deny($message);
        }

        return true;
    }

    /**
     * An explicit alias for `can()`.
     *
     * Exists so a call site that MEANS entitlement says so, and never has to be
     * re-read to find out which question it was asking. `can()` is kept because
     * it is the name in every existing application.
     */
    public function isEntitled(string $featureKey, mixed $scope = null, ?string $message = null): bool
    {
        return $this->can($featureKey, $scope, $message);
    }

    /**
     * Entitled AND `$amount` fits under the ceiling — the quota-aware read.
     *
     * Exactly what `can()` answered before 0.11.0, plus billable overage: the
     * ceiling is `included_quantity + overage_limit`, so a plan with an overage
     * allowance permits consumption past its included quantity and records the
     * excess as billable.
     *
     * This is a READ. Use `tryIncrement()` to gate a write — between this call
     * and the increment that follows it, a concurrent request can take the last
     * unit.
     */
    public function canConsume(
        string $featureKey,
        int $amount = 1,
        mixed $scope = null,
        ?string $message = null,
    ): bool {
        $this->lastError = null;

        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return $this->deny($message);
        }

        [$subscription, $feature, $config] = $resolved;

        if (! Quota::entitled((bool) $config->enabled, (string) $feature->type)) {
            return $this->deny($message);
        }

        if ($feature->type !== 'resource') {
            return true;
        }

        $allowed = Quota::canConsume(
            true,
            $this->includedQuantity($config),
            $this->overageLimit($config),
            $this->usageRow($subscription, $feature)?->used_quantity ?? 0,
            $amount,
        );

        return $allowed ? true : $this->deny($message);
    }

    /**
     * Remaining INCLUDED quantity for a resource feature; null when unlimited or
     * when the feature or its pivot configuration is missing.
     *
     * Deliberately not ceiling-aware: this is "how much of the allowance is
     * left", and a plan with an overage band can still consume after it reaches
     * zero. `canConsume()` is the question that accounts for overage.
     */
    public function remaining(string $featureKey, mixed $scope = null): ?int
    {
        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return null;
        }

        [$subscription, $feature, $config] = $resolved;

        $included = $this->includedQuantity($config);

        if ($included === null) {
            return null;
        }

        $used = $this->usageRow($subscription, $feature)?->used_quantity ?? 0;

        return max(0, $included - (int) $used);
    }

    /**
     * Billable overage recorded for this feature in the current period.
     *
     * Recorded, never derived. `max(0, used - included)` at read time is one
     * column cheaper and quietly wrong: a mid-period plan upgrade raises the
     * included quantity and erases overage that was genuinely incurred, possibly
     * after it was already reported to a billing provider.
     */
    public function overage(string $featureKey, mixed $scope = null): int
    {
        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return 0;
        }

        [$subscription, $feature] = $resolved;

        return (int) ($this->usageRow($subscription, $feature)?->overage_quantity ?? 0);
    }

    /**
     * Does this remaining quantity permit one more unit?
     *
     * `null` means UNLIMITED here. `remaining()` overloads null to mean both
     * "unlimited" and "no pivot config"; a caller that has already established
     * the config exists gets the unlimited reading.
     *
     * It used to be `$remaining === null || $remaining <= 0` -> deny, which
     * inverted the intent: `included_quantity = null` is documented as
     * unlimited, so the most generous configuration produced the most
     * restrictive outcome, and an unlimited allowance denied everything.
     *
     * Kept as a public helper because applications call it. Note it knows
     * nothing about billable overage — `Quota::allowsConsumption()` is the
     * function the service itself uses.
     */
    public static function allowsConsumption(?int $remaining): bool
    {
        if ($remaining === null) {
            return true;
        }

        return $remaining > 0;
    }

    /**
     * Return the last error message recorded by a failed can() call.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Increment usage for a resource feature by the given amount.
     *
     * Does NOT enforce the quota — use `tryIncrement()` for that. It does still
     * RECORD billable overage, because recording is not enforcing, and an
     * invoice built from a column only some code paths maintain is worse than
     * no column at all.
     *
     * @param  string  $featureKey  The feature key to increment
     * @param  int  $amount  The amount to increment by (default 1)
     * @param  mixed  $scope  The subscription scope (subscription, user, or null for the auth user)
     * @return bool True if the increment was applied
     */
    public function increment(string $featureKey, int $amount = 1, mixed $scope = null): bool
    {
        return $this->applyUsage($featureKey, $amount, $scope);
    }

    /**
     * Atomically check quota and increment usage for a metered feature.
     *
     * Why: the pattern `if ($fms->canConsume(...)) { $fms->increment(...); }` is
     * race-prone — two concurrent requests can both pass the check before either
     * increments, exceeding the quota. This locks the usage row, re-checks the
     * ceiling inside the transaction, and either commits the increment or
     * returns false.
     *
     * The ceiling is `included_quantity + overage_limit`. With no overage limit
     * configured — which is every row in every database written before 0.11.0,
     * because the column was read by nothing — that is exactly the included
     * quantity, so nothing changes for anyone who has not opted in.
     *
     * @return bool True if the quota allowed it and the increment was applied
     */
    public function tryIncrement(string $featureKey, int $amount = 1, mixed $scope = null): bool
    {
        if ($amount < 0) {
            // A negative "consume" is a refund wearing a disguise, and it would
            // walk straight past `used + amount <= ceiling`. decrement() exists
            // for it.
            throw new \InvalidArgumentException(
                "tryIncrement was given a negative amount ({$amount}). Use decrement() to return "
                .'quota; a negative increment bypasses the ceiling it is meant to enforce.'
            );
        }

        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return false;
        }

        [$subscription, $feature, $config] = $resolved;

        if ($feature->type !== 'resource') {
            return false;
        }

        if (! (bool) $config->enabled) {
            return false;
        }

        $included = $this->includedQuantity($config);
        $ceiling = Quota::consumptionCeiling($included, $this->overageLimit($config));

        return DB::transaction(function () use ($subscription, $feature, $amount, $included, $ceiling) {
            $usage = $this->getOrCreateCurrentPeriodUsage($subscription, $feature);

            // Re-fetch with a row lock so concurrent transactions serialize.
            $locked = FeatureUsage::query()
                ->whereKey($usage->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $used = (int) $locked->used_quantity;

            if (! Quota::allowsConsumption($used, $amount, $ceiling)) {
                return false;
            }

            $this->writeUsage($subscription, $feature, $locked, $amount, $included);

            return true;
        });
    }

    /**
     * Decrement usage for a resource feature by the given amount.
     *
     * Unwinds recorded overage by the same arithmetic that recorded it —
     * `Quota::overageDelta()` is signed, so a refund of 30 units when only 10 of
     * them were billable credits 10, not 30. One function serves both directions
     * so they cannot drift apart.
     *
     * @return bool True if the decrement was applied
     */
    public function decrement(string $featureKey, int $amount = 1, mixed $scope = null): bool
    {
        return $this->applyUsage($featureKey, -$amount, $scope);
    }

    /**
     * Get the current usage amount for a resource feature.
     * Why: Provides visibility into current consumption for UI display.
     */
    public function usage(string $featureKey, mixed $scope = null): int
    {
        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return 0;
        }

        [$subscription, $feature] = $resolved;

        return (int) ($this->usageRow($subscription, $feature)?->used_quantity ?? 0);
    }

    /**
     * Reset usage for all resource features for a subscription's new billing period.
     * Why: Called when a subscription renews to start fresh usage tracking.
     *
     * Overage resets with it, for free: it is a column on the per-period row, so
     * a new period is a new row starting at zero.
     */
    public function resetPeriodUsage($subscription): void
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->startOfDay();
        $periodEnd = $periodStart->addMonth();

        $product = $subscription->product();

        if (! $product) {
            return;
        }

        $resourceFeatures = $product->productFeatures()
            ->where('type', 'resource')
            ->get();

        foreach ($resourceFeatures as $feature) {
            FeatureUsage::firstOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'product_feature_id' => $feature->id,
                    'period_start' => $periodStart,
                ],
                [
                    'period_end' => $periodEnd,
                    'used_quantity' => 0,
                    'overage_quantity' => 0,
                ]
            );
        }
    }

    // ---- Internals -------------------------------------------------------

    /** Record `$message` as the last error and answer false. */
    protected function deny(?string $message): bool
    {
        if ($message) {
            $this->lastError = $message;
        }

        return false;
    }

    /**
     * subscription → ProductFeature → pivot, or null when any link is missing.
     *
     * One walk, used by every public method. It was written out five times, and
     * five copies of a lookup chain is five chances for one of them to check a
     * condition the others do not — which is precisely how `can()` came to
     * ignore the pivot's `enabled` flag for resource features while every other
     * runtime honoured it.
     *
     * @return array{0:mixed,1:mixed,2:mixed}|null  [subscription, feature, pivot]
     */
    protected function resolve(string $featureKey, mixed $scope): ?array
    {
        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            return null;
        }

        $feature = $this->resolveFeature($featureKey);

        if (! $feature) {
            return null;
        }

        $product = $subscription->product();

        if (! $product) {
            return null;
        }

        // `whereKey`, not `where('product_features.id', ...)`. The literal was
        // in every copy of this walk and it is wrong for exactly the installs
        // this package documents support for: `fms.tables.product_features`
        // exists so a consumer can rename or prefix the table, and
        // `laravel-catalog` writes to `catalog_product_features`. The literal
        // names a table that is not in the query, so the whole lookup fails with
        // "no such column" rather than degrading.
        $config = $product->productFeatures()
            ->whereKey($feature->getKey())
            ->first()?->pivot;

        if (! $config) {
            return null;
        }

        return [$subscription, $feature, $config];
    }

    /** The configured ProductFeature model row for a key, or null. */
    protected function resolveFeature(string $featureKey): mixed
    {
        $productFeatureClass = config('fms.product_feature_model');

        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return null;
        }

        return $productFeatureClass::query()->where('key', $featureKey)->first();
    }

    /** The most recent metering row for this subscription + feature, or null. */
    protected function usageRow($subscription, $feature): ?FeatureUsage
    {
        return $subscription->featureUsages()
            ->where('product_feature_id', $feature->id)
            ->orderByDesc('period_start')
            ->first();
    }

    /** The pivot's included quantity; null means UNLIMITED. */
    protected function includedQuantity($config): ?int
    {
        return $config->included_quantity === null ? null : (int) $config->included_quantity;
    }

    /**
     * The pivot's billable-overage allowance; null or 0 means NO overage.
     *
     * Null had to mean "none" rather than "unbounded": the column was stored by
     * three runtimes and consulted by none until 0.11.0, so every row in every
     * existing database is either null or a number somebody typed hoping it
     * would work. Reading null as unbounded would have turned each untouched row
     * into an unlimited spending authority the moment this shipped.
     */
    protected function overageLimit($config): ?int
    {
        $limit = $config->overage_limit ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Apply a signed usage change without enforcing the ceiling, recording the
     * billable part. Shared by `increment()` and `decrement()`.
     */
    protected function applyUsage(string $featureKey, int $amount, mixed $scope): bool
    {
        $resolved = $this->resolve($featureKey, $scope);

        if ($resolved === null) {
            return false;
        }

        [$subscription, $feature, $config] = $resolved;

        if ($feature->type !== 'resource') {
            return false;
        }

        $included = $this->includedQuantity($config);

        return DB::transaction(function () use ($subscription, $feature, $amount, $included) {
            $usage = $this->getOrCreateCurrentPeriodUsage($subscription, $feature);

            $locked = FeatureUsage::query()
                ->whereKey($usage->getKey())
                ->lockForUpdate()
                ->first() ?? $usage;

            $this->writeUsage($subscription, $feature, $locked, $amount, $included);

            return true;
        });
    }

    /**
     * Write a signed usage change and its billable share to one metering row.
     *
     * Both columns move in the same statement. Splitting them would leave a
     * window in which usage is recorded and the overage it incurred is not —
     * and an under-reported invoice is the failure here that cannot be repaired
     * after the fact.
     */
    protected function writeUsage(
        $subscription,
        $feature,
        FeatureUsage $usage,
        int $amount,
        ?int $included,
    ): void {
        $usedBefore = (int) $usage->used_quantity;
        $overageBefore = (int) ($usage->overage_quantity ?? 0);

        $overageDelta = Quota::overageDelta($usedBefore, $amount, $included);

        $usedAfter = max(0, $usedBefore + $amount);
        $overageAfter = max(0, $overageBefore + $overageDelta);

        $usage->forceFill([
            'used_quantity' => $usedAfter,
            'overage_quantity' => $overageAfter,
        ])->save();

        if ($overageDelta > 0) {
            // Only on the way up. A refund lowers the recorded total and does
            // not fire: a credit is a decision about money, and inventing one
            // from a usage correction is not this package's call.
            FeatureOverageRecorded::dispatch(
                $subscription,
                (string) $feature->key,
                $feature->id,
                $overageDelta,
                $overageAfter,
                $usage,
                $usage->period_start,
                $usage->period_end,
            );
        }
    }

    /**
     * Get or create a FeatureUsage record for the current billing period.
     * Why: Ensures usage is tracked per billing period for accurate metering.
     */
    protected function getOrCreateCurrentPeriodUsage($subscription, $feature): FeatureUsage
    {
        $now = CarbonImmutable::now();

        // Determine period based on subscription renewal date
        $periodStart = $subscription->renews_at
            ? $subscription->renews_at->subMonth()->startOfDay()
            : $subscription->created_at->startOfDay();

        $periodEnd = $subscription->renews_at
            ? $subscription->renews_at->startOfDay()
            : $periodStart->addMonth();

        // If we're past the period end, calculate the current period
        if ($now->greaterThan($periodEnd)) {
            $monthsSincePeriodEnd = $periodEnd->diffInMonths($now);
            $periodStart = $periodEnd->addMonths($monthsSincePeriodEnd);
            $periodEnd = $periodStart->addMonth();
        }

        return FeatureUsage::firstOrCreate(
            [
                'subscription_id' => $subscription->id,
                'product_feature_id' => $feature->id,
                'period_start' => $periodStart,
            ],
            [
                'period_end' => $periodEnd,
                'used_quantity' => 0,
                'overage_quantity' => 0,
            ]
        );
    }

    /**
     * Resolve the current subscription scope from an explicit scope, a
     * subscription instance, or the currently authenticated user.
     *
     * Both models are CONFIGURATION (`fms.subscription_model`,
     * `fms.user_model`), like `fms.product_feature_model` beside them. They used
     * to be hard-coded to the consuming application's own classes with a comment
     * telling the reader to replace them -- an instruction nobody can follow,
     * because by then the file is in `vendor/`. Any app without those exact
     * classes matched neither `instanceof`, so no subscription resolved and
     * every `can()` denied.
     */
    protected function resolveSubscriptionScope(mixed $scope)
    {
        $billingSubscriptionClass = config('fms.subscription_model');

        if (! $billingSubscriptionClass || ! class_exists($billingSubscriptionClass)) {
            return null;
        }

        if ($scope instanceof $billingSubscriptionClass) {
            return $scope;
        }

        $userClass = config('fms.user_model');
        $isUser = $userClass && class_exists($userClass) && $scope instanceof $userClass;

        if ($scope instanceof Authenticatable || $isUser) {
            return $billingSubscriptionClass::query()
                ->where('owner_type', $scope::class)
                ->where('owner_id', (string) $scope->id)
                ->active()
                ->latest()
                ->first();
        }

        $user = Auth::user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return $billingSubscriptionClass::query()
            ->where('owner_type', $user::class)
            ->where('owner_id', (string) $user->id)
            ->active()
            ->latest()
            ->first();
    }
}
