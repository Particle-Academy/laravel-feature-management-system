<?php

namespace ParticleAcademy\Fms\Services;

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use ParticleAcademy\Fms\Services\FmsFeatureGroupRegistry;
use ParticleAcademy\Fms\Models\FeatureGroupAssignment;
use ParticleAcademy\Fms\ValueObjects\FeatureGroup;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Feature Manager Service
 *
 * Resolution order:
 *   0. Pre-strategies (app-supplied, run in registration order;
 *      first non-null result wins and is authoritative — used for
 *      subscription / entitlement integrations that need to short-
 *      circuit the standard chain). See `registerPreStrategy()`.
 *   1. Gate / Policy
 *   2. Registry (FmsFeatureRegistry)
 *   3. Feature Groups (FmsFeatureGroupRegistry) — OR'd across all
 *      groups enabled for the subject (via pivot or callable gate)
 *   4. Config (config/fms.php features.{key}.enabled)
 *   5. A SUBCLASS EXTENSION HOOK — `checkDatabaseFeature()`. This package's
 *      own implementation always declines, so nothing resolves from the
 *      database out of the box.
 *
 * Step 5 was documented as "Database lookups" until 0.11.0, which described a
 * resolution strategy that does not exist: `checkDatabaseFeature()`,
 * `getDatabaseResourceRemaining()` and `getDatabaseResourceUsage()` return
 * `false`, `null` and `0`. They are legitimate `protected` extension points — a
 * subclass overriding one gets a working hook, which is why they are still
 * called — but a reader configuring `'default_strategy' => 'database'` got
 * nothing at all, and `default_strategy` was itself read by no code in the
 * package. The claim is gone; the hooks stay. Implementing a real database step
 * would change resolution order for every existing app to serve nobody who
 * asked, and the extension point that DOES something already exists twice over
 * (`registerPreStrategy()` here, `FeatureSource` in the Node and Python twins).
 *
 * `canAccess()` answers ENTITLEMENT and has always done so here: a resource
 * feature is on when its `enabled`/`check` says so, whatever `remaining()`
 * reports. `canConsume()` is the quota-aware read. The subscription-scoped
 * `Fms` service used to answer the OTHER question under the name `can()`; from
 * 0.11.0 the two agree.
 *
 * Resource limits aggregate as MAX across all enabled groups containing
 * the feature, then fall back to the feature's own limit. Pre-remaining
 * strategies (see `registerPreRemainingStrategy()`) get the same
 * "first non-null wins" treatment for resource lookups.
 */
class FeatureManager implements FeatureManagerInterface
{
    /**
     * Named pre-strategies for boolean access checks. Run before Gate.
     * First strategy returning non-null wins and is authoritative.
     *
     * @var array<string, callable(string,mixed,mixed):?bool>
     */
    protected array $preStrategies = [];

    /**
     * Named pre-strategies for resource `remaining()` lookups. Same
     * "first non-null wins" semantics as `$preStrategies`.
     *
     * @var array<string, callable(string,mixed,mixed):?int>
     */
    protected array $preRemainingStrategies = [];

    public function __construct(
        protected FmsFeatureRegistry $registry,
        protected ?FmsFeatureGroupRegistry $groupRegistry = null,
    ) {}

    /**
     * Register a named pre-strategy that runs before Gate / Registry /
     * Groups / Config / Database for `canAccess()`. The strategy receives
     * `(feature, user, context)` and returns `?bool`:
     *
     *   - true  → access granted, no further strategies consulted
     *   - false → access denied, no further strategies consulted
     *   - null  → "I don't know", fall through to the next strategy
     *
     * Strategies run in registration order. Re-registering an existing
     * `$name` replaces the previous closure.
     *
     * Typical use case: layering a subscription / entitlement check via
     * the app's billing service so paid features can't be granted by
     * accident through a Registry / Config rule.
     */
    public function registerPreStrategy(string $name, callable $strategy): void
    {
        $this->preStrategies[$name] = $strategy;
    }

    /**
     * Remove a previously-registered boolean pre-strategy. Safe to call
     * with an unknown `$name` — no-ops if not registered.
     */
    public function unregisterPreStrategy(string $name): void
    {
        unset($this->preStrategies[$name]);
    }

    /**
     * Registered pre-strategy names, in evaluation order. Useful for
     * introspection (devtools, the `fms:resolve` artisan command).
     *
     * @return array<int, string>
     */
    public function preStrategyNames(): array
    {
        return array_keys($this->preStrategies);
    }

    /**
     * Register a named pre-strategy for `remaining()` lookups. Same
     * semantics as `registerPreStrategy()` but the return type is
     * `?int` — null means fall through, an integer is authoritative.
     */
    public function registerPreRemainingStrategy(string $name, callable $strategy): void
    {
        $this->preRemainingStrategies[$name] = $strategy;
    }

    public function unregisterPreRemainingStrategy(string $name): void
    {
        unset($this->preRemainingStrategies[$name]);
    }

    /** @return array<int, string> */
    public function preRemainingStrategyNames(): array
    {
        return array_keys($this->preRemainingStrategies);
    }

    public function canAccess(string $feature, mixed $user = null, mixed $context = null): bool
    {
        $user = $user ?? Auth::user();

        // Pre-strategies (registration order). First non-null wins; null
        // means "I don't know" and we fall through. Sits above Gate so a
        // subscription / entitlement service can be authoritative even
        // when a stray Gate would otherwise allow.
        foreach ($this->preStrategies as $strategy) {
            $verdict = $strategy($feature, $user, $context);
            if ($verdict !== null) {
                return (bool) $verdict;
            }
        }

        // Gate / Policy is the only authoritative override — if defined, its
        // verdict is final (allow OR deny), bypassing other sources entirely.
        if (Gate::has($feature)) {
            return Gate::forUser($user)->allows($feature, $context);
        }

        // OR semantics across the remaining sources: any source that
        // says "enabled" turns the feature on. A registry/config feature
        // with `enabled: false` does NOT block a group from activating
        // it — groups are additive by design.
        $definition = $this->registry->definition($feature);
        if ($definition !== null && $this->checkDefinition($definition, $user, $context)) {
            return true;
        }

        if ($this->isEnabledViaGroups($feature, $user, $context)) {
            return true;
        }

        $configValue = config("fms.features.{$feature}.enabled", null);
        if ($configValue !== null && $this->evaluateConfigValue($configValue, $user, $context)) {
            return true;
        }

        // Database fallback (extension hook).
        if ($this->hasDatabaseSupport()) {
            return $this->checkDatabaseFeature($feature, $user, $context);
        }

        return false;
    }

    public function isEnabled(string $feature, mixed $user = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $user, $context);
    }

    public function hasFeature(string $feature, mixed $user = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $user, $context);
    }

    /**
     * An explicit alias for `canAccess()`.
     *
     * `canAccess` answers entitlement — is this feature granted — regardless of
     * remaining quota. Nothing about the name says which of the two questions it
     * is, and the answer used to depend on which layer a feature was defined in,
     * so this name exists for a call site that means entitlement and wants to
     * say so.
     */
    public function isEntitled(string $feature, mixed $user = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $user, $context);
    }

    /**
     * Entitled AND `$amount` fits in the remaining quota.
     *
     * The quota-aware read, for a caller that wants what `canAccess()` used to
     * answer for a catalog-sourced resource feature. `null` remaining is
     * unlimited and always allows.
     *
     * **This is a READ, not a gate.** Between it and the write that follows,
     * another request can spend the last unit. This class owns no storage, so it
     * cannot close that window; `Fms::tryIncrement()` takes a row lock and can.
     */
    public function canConsume(
        string $feature,
        mixed $user = null,
        int $amount = 1,
        mixed $context = null,
    ): bool {
        $user = $user ?? Auth::user();

        if (! $this->canAccess($feature, $user, $context)) {
            return false;
        }

        $remaining = $this->remaining($feature, $user, $context);

        // Not a resource feature, or unlimited: entitlement is the whole answer.
        return $remaining === null || $remaining >= $amount;
    }

    public function remaining(string $feature, mixed $user = null, mixed $context = null): ?int
    {
        $user = $user ?? Auth::user();

        // Pre-remaining strategies run first. First non-null wins. Lets
        // a subscription / quota service answer authoritatively without
        // forcing every resource feature to model its limits in the
        // registry/config.
        foreach ($this->preRemainingStrategies as $strategy) {
            $verdict = $strategy($feature, $user, $context);
            if ($verdict !== null) {
                return max(0, (int) $verdict);
            }
        }

        // Group-supplied limit (max across enabled groups) takes precedence
        // when a group provides an override, since a paid plan should be
        // able to lift the base feature's limit.
        $groupLimit = $this->resolveGroupLimitOverride($feature, $user, $context);

        // Registry limit
        $definition = $this->registry->definition($feature);
        if ($definition !== null && ($definition['type'] ?? null) === 'resource') {
            return $this->getResourceRemaining(
                $this->withMergedLimit($definition, $groupLimit),
                $feature,
                $user,
                $context
            );
        }

        // Config limit
        $config = config("fms.features.{$feature}", null);
        if ($config !== null && ($config['type'] ?? null) === 'resource') {
            return $this->getResourceRemaining(
                $this->withMergedLimit($config, $groupLimit),
                $feature,
                $user,
                $context
            );
        }

        // No registry/config feature definition but a group does override
        // the limit — treat as a resource feature with that limit.
        if ($groupLimit !== null) {
            return $this->getResourceRemaining(
                ['type' => 'resource', 'limit' => $groupLimit],
                $feature,
                $user,
                $context
            );
        }

        if ($this->hasDatabaseSupport()) {
            return $this->getDatabaseResourceRemaining($feature, $user, $context);
        }

        return null;
    }

    public function enabled(mixed $user = null, mixed $context = null): array
    {
        $user = $user ?? Auth::user();
        $enabled = [];

        foreach (array_keys($this->registry->all()) as $feature) {
            if ($this->canAccess($feature, $user, $context)) {
                $enabled[] = $feature;
            }
        }

        $configFeatures = config('fms.features', []);
        foreach (array_keys($configFeatures) as $feature) {
            if (!in_array($feature, $enabled, true) && $this->canAccess($feature, $user, $context)) {
                $enabled[] = $feature;
            }
        }

        // Features only exposed through groups also count.
        if ($this->groupRegistry !== null) {
            foreach ($this->enabledGroupsFor($user, $context) as $groupKey) {
                foreach ($this->groupRegistry->resolvedFeatures($groupKey) as $feature) {
                    if (!in_array($feature, $enabled, true)) {
                        $enabled[] = $feature;
                    }
                }
            }
        }

        return array_values(array_unique($enabled));
    }

    /**
     * Trace a feature's resolution. Returns the path FeatureManager would
     * take with structured payload. Surfaces "why is this on/off?" — used
     * by the `fms:resolve` artisan command and by app-level devtools.
     *
     * @return array{feature:string, source:string, enabled:bool, detail:array<string,mixed>}
     */
    public function explain(string $feature, mixed $user = null, mixed $context = null): array
    {
        $user = $user ?? Auth::user();

        // Pre-strategies first — they out-rank Gate in canAccess() so the
        // explanation must reflect that, naming the strategy that
        // answered so devtools can show "blocked by subscription" etc.
        foreach ($this->preStrategies as $name => $strategy) {
            $verdict = $strategy($feature, $user, $context);
            if ($verdict !== null) {
                return [
                    'feature' => $feature,
                    'source' => 'pre-strategy',
                    'enabled' => (bool) $verdict,
                    'detail' => ['name' => $name],
                ];
            }
        }

        // Gate is authoritative — return its verdict regardless of value.
        if (Gate::has($feature)) {
            return [
                'feature' => $feature,
                'source' => 'gate',
                'enabled' => Gate::forUser($user)->allows($feature, $context),
                'detail' => ['gate' => $feature],
            ];
        }

        // OR semantics: return the first source that says enabled. Order is
        // registry → group → config so a "richer" source wins over a fallback.
        $definition = $this->registry->definition($feature);
        if ($definition !== null && $this->checkDefinition($definition, $user, $context)) {
            return [
                'feature' => $feature,
                'source' => 'registry',
                'enabled' => true,
                'detail' => $definition,
            ];
        }

        $matchingGroups = $this->matchingEnabledGroups($feature, $user, $context);
        if ($matchingGroups !== []) {
            return [
                'feature' => $feature,
                'source' => 'group',
                'enabled' => true,
                'detail' => [
                    'groups' => $matchingGroups,
                    'limit_override' => $this->resolveGroupLimitOverride($feature, $user, $context),
                ],
            ];
        }

        $configValue = config("fms.features.{$feature}.enabled", null);
        if ($configValue !== null && $this->evaluateConfigValue($configValue, $user, $context)) {
            return [
                'feature' => $feature,
                'source' => 'config',
                'enabled' => true,
                'detail' => ['enabled' => $configValue],
            ];
        }

        // Nothing enabled. Report the most-specific source that *defined*
        // the feature, even if it disabled it — useful for "why is this off?"
        if ($definition !== null) {
            return [
                'feature' => $feature,
                'source' => 'registry',
                'enabled' => false,
                'detail' => $definition,
            ];
        }
        if ($configValue !== null) {
            return [
                'feature' => $feature,
                'source' => 'config',
                'enabled' => false,
                'detail' => ['enabled' => $configValue],
            ];
        }

        return [
            'feature' => $feature,
            'source' => 'none',
            'enabled' => false,
            'detail' => [],
        ];
    }

    /**
     * Group keys enabled for the subject — both pivot-assigned (when
     * the subject is a HasFeatureGroups model) and `enabled`-callable
     * matches.
     *
     * @return array<int,string>
     */
    public function enabledGroupsFor(mixed $user = null, mixed $context = null): array
    {
        if ($this->groupRegistry === null) {
            return [];
        }
        $user = $user ?? Auth::user();
        $keys = [];

        // Pivot-assigned (only meaningful when the subject persists a model).
        if ($user !== null && method_exists($user, 'featureGroups')) {
            foreach ($user->featureGroups() as $groupKey) {
                $keys[] = $groupKey;
            }
        }

        // Callable-gated groups.
        foreach ($this->groupRegistry->all() as $key => $group) {
            if ($group->isEnabledByCallable($user, $context)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    protected function isEnabledViaGroups(string $feature, mixed $user, mixed $context): bool
    {
        return $this->matchingEnabledGroups($feature, $user, $context) !== [];
    }

    /**
     * Subset of enabled groups that ALSO contain the given feature.
     *
     * @return array<int,string>
     */
    protected function matchingEnabledGroups(string $feature, mixed $user, mixed $context): array
    {
        if ($this->groupRegistry === null) {
            return [];
        }
        $matching = [];
        foreach ($this->enabledGroupsFor($user, $context) as $groupKey) {
            if (in_array($feature, $this->groupRegistry->resolvedFeatures($groupKey), true)) {
                $matching[] = $groupKey;
            }
        }
        return $matching;
    }

    /**
     * Maximum of all `limit` overrides across enabled groups that contain
     * this feature. Returns null if no group provides an override (caller
     * falls back to the registry/config limit).
     */
    protected function resolveGroupLimitOverride(string $feature, mixed $user, mixed $context): ?int
    {
        if ($this->groupRegistry === null) {
            return null;
        }
        $maxLimit = null;
        foreach ($this->matchingEnabledGroups($feature, $user, $context) as $groupKey) {
            $overrides = $this->groupRegistry->resolvedOverrides($groupKey);
            if (!isset($overrides[$feature]['limit'])) {
                continue;
            }
            $candidate = $overrides[$feature]['limit'];
            $candidate = is_callable($candidate)
                ? (int) call_user_func($candidate, $user, $context)
                : (int) $candidate;
            if ($maxLimit === null || $candidate > $maxLimit) {
                $maxLimit = $candidate;
            }
        }
        return $maxLimit;
    }

    /**
     * Returns a copy of the feature definition with the limit replaced by
     * the group-supplied override if it's higher (max wins). Preserves the
     * other fields untouched.
     *
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    protected function withMergedLimit(array $definition, ?int $groupLimit): array
    {
        if ($groupLimit === null) {
            return $definition;
        }
        $current = $definition['limit'] ?? null;
        $resolvedCurrent = is_callable($current) ? null : (int) ($current ?? 0);
        if ($resolvedCurrent !== null && $resolvedCurrent >= $groupLimit) {
            return $definition;
        }
        $merged = $definition;
        $merged['limit'] = $groupLimit;
        return $merged;
    }

    protected function checkDefinition(array $definition, mixed $user, mixed $context): bool
    {
        if (isset($definition['check']) && is_callable($definition['check'])) {
            return (bool) call_user_func($definition['check'], $user, $context);
        }
        if (isset($definition['enabled'])) {
            return $this->evaluateConfigValue($definition['enabled'], $user, $context);
        }
        return true;
    }

    protected function evaluateConfigValue(mixed $value, mixed $user, mixed $context): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_callable($value)) {
            return (bool) call_user_func($value, $user, $context);
        }
        return false;
    }

    protected function getResourceRemaining(array $definition, string $feature, mixed $user, mixed $context): ?int
    {
        if (isset($definition['remaining']) && is_callable($definition['remaining'])) {
            return $this->callDefinitionCallback($definition['remaining'], $feature, $user, $context);
        }
        $limit = $definition['limit'] ?? config("fms.features.{$feature}.limit", null);
        if (is_callable($limit)) {
            $limit = (int) call_user_func($limit, $user, $context);
        }
        if ($limit === null) {
            return null;
        }
        $usage = $this->getResourceUsage($definition, $feature, $user, $context);
        return max(0, (int) $limit - (int) $usage);
    }

    protected function getResourceUsage(array $definition, string $feature, mixed $user, mixed $context): int
    {
        if (isset($definition['usage']) && is_callable($definition['usage'])) {
            return (int) $this->callDefinitionCallback($definition['usage'], $feature, $user, $context);
        }
        if ($this->hasDatabaseSupport()) {
            return $this->getDatabaseResourceUsage($feature, $user, $context);
        }
        return 0;
    }

    /**
     * Invoke a feature-definition callback as `($user, $context)`.
     *
     * That is the convention everywhere else in this class — `check`, `enabled`
     * and `limit` all receive `($user, $context)` — and it is what the README
     * has always documented. `usage` and `remaining` were the two outliers,
     * invoked as `($feature, $user, $context)` with the key FIRST, so anyone
     * following the docs bound `$user` to a string and measured the wrong
     * thing. Reported by GuardCard.net, whose allowance never ran out.
     *
     * `$feature` is redundant in the first place: the callback is defined inside
     * `features.<key>`, so the key is already known where it is written.
     *
     * ## The three-parameter escape hatch
     *
     * A callback declaring three parameters was written against the old order
     * — deliberately, by someone who read the source rather than the docs, since
     * the docs never described it. Passing it two arguments would not fail; it
     * would shift every argument by one and keep returning numbers, which is the
     * silent-wrong-answer this fix exists to end. So arity picks the order, and
     * the legacy shape is deprecated out loud rather than broken quietly.
     *
     * Remove at 1.0.
     */
    protected function callDefinitionCallback(callable $callback, string $feature, mixed $user, mixed $context): mixed
    {
        if ($this->callbackArity($callback) === 3) {
            trigger_error(
                "FMS: the `{$feature}` usage/remaining callback takes three parameters, which is the pre-0.8 "
                .'`($feature, $user, $context)` order. Change it to `($user, $context)` — the feature key is '
                .'already known where the callback is defined. Support for the old order is removed at 1.0.',
                E_USER_DEPRECATED,
            );

            return call_user_func($callback, $feature, $user, $context);
        }

        return call_user_func($callback, $user, $context);
    }

    /** Declared parameter count, or null when it cannot be determined. */
    protected function callbackArity(callable $callback): ?int
    {
        try {
            $reflection = is_array($callback)
                ? new \ReflectionMethod($callback[0], $callback[1])
                : new \ReflectionFunction(\Closure::fromCallable($callback));

            return $reflection->getNumberOfParameters();
        } catch (\ReflectionException) {
            // An uninspectable callable (some invokable internals) gets the
            // documented signature rather than an exception — guessing the
            // legacy order for something we cannot read would reintroduce the
            // bug for the one case we know least about.
            return null;
        }
    }

    /**
     * Always true: `FeatureUsage` ships inside this package, so the class always
     * exists. That is harmless — the three hooks below decline by default, so
     * "has database support" gates nothing — and it is left alone because a
     * subclass may override it to switch its own hooks off.
     */
    protected function hasDatabaseSupport(): bool
    {
        return class_exists(\ParticleAcademy\Fms\Models\FeatureUsage::class);
    }

    /**
     * Subclass extension hook — NOT a database resolution strategy.
     *
     * This package declines. It is the terminal `return` of `canAccess()`, so a
     * subclass overriding it gets a working last-resort source. The call sites
     * are kept for exactly that reason; what was removed in 0.11.0 is the
     * config file's claim that the package resolves features from the database
     * on its own, which it never did.
     */
    protected function checkDatabaseFeature(string $feature, mixed $user, mixed $context): bool
    {
        return false;
    }

    /** Subclass extension hook. Declines; see `checkDatabaseFeature()`. */
    protected function getDatabaseResourceRemaining(string $feature, mixed $user, mixed $context): ?int
    {
        return null;
    }

    /** Subclass extension hook. Declines; see `checkDatabaseFeature()`. */
    protected function getDatabaseResourceUsage(string $feature, mixed $user, mixed $context): int
    {
        return 0;
    }
}
