<?php

namespace ParticleAcademy\Fms\Services;

use ParticleAcademy\Fms\Models\FeatureUsage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Fms service
 * Why: Centralizes feature entitlement checks for Products and subscriptions,
 * providing a simple API used by the FMS facade (FMS::can, remaining, lastError).
 * 
 * NOTE: This service requires your application to have:
 * - BillingSubscription model with active() scope, product() method, featureUsages() relationship
 * - ProductFeature model
 * - Product model with productFeatures() relationship
 */
class Fms
{
    protected ?string $lastError = null;

    /**
     * Check whether the given feature is allowed for the resolved scope.
     * Returns a plain bool; when false and a message is provided, it will be
     * recorded as the last error for workflow consumption.
     */
    public function can(string $featureKey, mixed $scope = null, ?string $message = null): bool
    {
        $this->lastError = null;

        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            if ($message) {
                $this->lastError = $message;
            }

            return false;
        }

        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return false;
        }
        
        $feature = $productFeatureClass::query()->where('key', $featureKey)->first();

        if (! $feature) {
            if ($message) {
                $this->lastError = $message;
            }

            return false;
        }

        $product = $subscription->product();

        if (! $product) {
            if ($message) {
                $this->lastError = $message;
            }

            return false;
        }

        $config = $product->productFeatures()->where('product_features.id', $feature->id)->first()?->pivot;

        if (! $config) {
            if ($message) {
                $this->lastError = $message;
            }

            return false;
        }

        // Boolean features respect the enabled flag only.
        if ($feature->type === 'boolean') {
            if (! $config->enabled) {
                if ($message) {
                    $this->lastError = $message;
                }

                return false;
            }

            return true;
        }

        // Resource features check remaining quantity against usage.
        $remaining = $this->remaining($featureKey, $subscription);

        if ($remaining === null || $remaining <= 0) {
            if ($message) {
                $this->lastError = $message;
            }

            return false;
        }

        return true;
    }

    /**
     * Compute remaining quantity for a resource feature for the given scope.
     * Returns null when the feature or configuration is missing.
     */
    public function remaining(string $featureKey, mixed $scope = null): ?int
    {
        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            return null;
        }

        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return null;
        }
        
        $feature = $productFeatureClass::query()->where('key', $featureKey)->first();

        if (! $feature) {
            return null;
        }

        $product = $subscription->product();

        if (! $product) {
            return null;
        }

        $config = $product->productFeatures()->where('product_features.id', $feature->id)->first()?->pivot;

        if (! $config || $config->included_quantity === null) {
            return null;
        }

        $usage = $subscription->featureUsages()
            ->where('product_feature_id', $feature->id)
            ->orderByDesc('period_start')
            ->first();

        $used = $usage?->used_quantity ?? 0;

        return max(0, (int) $config->included_quantity - (int) $used);
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
     * Why: Tracks consumption of metered features (tokens, API calls, etc.).
     *
     * @param  string  $featureKey  The feature key to increment
     * @param  int  $amount  The amount to increment by (default 1)
     * @param  mixed  $scope  The subscription scope (BillingSubscription, User, or null for auth user)
     * @return bool True if increment succeeded, false if feature not found or no subscription
     */
    public function increment(string $featureKey, int $amount = 1, mixed $scope = null): bool
    {
        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            return false;
        }

        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return false;
        }
        
        $feature = $productFeatureClass::query()->where('key', $featureKey)->first();

        if (! $feature || $feature->type !== 'resource') {
            return false;
        }

        $usage = $this->getOrCreateCurrentPeriodUsage($subscription, $feature);
        $usage->increment('used_quantity', $amount);

        return true;
    }

    /**
     * Decrement usage for a resource feature by the given amount.
     * Why: Allows correcting or refunding metered feature consumption.
     *
     * @param  string  $featureKey  The feature key to decrement
     * @param  int  $amount  The amount to decrement by (default 1)
     * @param  mixed  $scope  The subscription scope
     * @return bool True if decrement succeeded, false if feature not found or no subscription
     */
    public function decrement(string $featureKey, int $amount = 1, mixed $scope = null): bool
    {
        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            return false;
        }

        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return false;
        }
        
        $feature = $productFeatureClass::query()->where('key', $featureKey)->first();

        if (! $feature || $feature->type !== 'resource') {
            return false;
        }

        $usage = $this->getOrCreateCurrentPeriodUsage($subscription, $feature);

        // Don't allow negative usage
        $newQuantity = max(0, $usage->used_quantity - $amount);
        $usage->update(['used_quantity' => $newQuantity]);

        return true;
    }

    /**
     * Get the current usage amount for a resource feature.
     * Why: Provides visibility into current consumption for UI display.
     */
    public function usage(string $featureKey, mixed $scope = null): int
    {
        $subscription = $this->resolveSubscriptionScope($scope);

        if (! $subscription) {
            return 0;
        }

        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            return false;
        }
        
        $feature = $productFeatureClass::query()->where('key', $featureKey)->first();

        if (! $feature) {
            return 0;
        }

        $usage = $subscription->featureUsages()
            ->where('product_feature_id', $feature->id)
            ->orderByDesc('period_start')
            ->first();

        return $usage?->used_quantity ?? 0;
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
            ]
        );
    }

    /**
     * Reset usage for all resource features for a subscription's new billing period.
     * Why: Called when a subscription renews to start fresh usage tracking.
     */
    public function resetPeriodUsage($subscription): void
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->startOfDay();
        $periodEnd = $periodStart->addMonth();

        // Get all resource features for this subscription's product
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
                ]
            );
        }
    }

    /**
     * Resolve the current subscription scope from an explicit scope, a
     * BillingSubscription instance, or the currently authenticated user.
     * 
     * NOTE: Update BillingSubscription and User class references to match your application
     */
    protected function resolveSubscriptionScope(mixed $scope)
    {
        // Replace with your BillingSubscription model class
        $billingSubscriptionClass = \App\Models\BillingSubscription::class;
        
        if ($scope instanceof $billingSubscriptionClass) {
            return $scope;
        }

        // Replace with your User model class
        $userClass = \App\Models\User::class;

        if ($scope instanceof Authenticatable || $scope instanceof $userClass) {
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

