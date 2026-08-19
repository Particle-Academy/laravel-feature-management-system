<?php

namespace ParticleAcademy\Fms\Contracts;

/**
 * Feature Manager Interface
 *
 * Why: Defines the contract for feature access control. The `$subject`
 * parameter accepts any model — historically a User (Authenticatable),
 * but with feature groups it can be any HasFeatureGroups model:
 * Product, Team, Org, anything. Pass `null` to fall back to the
 * authenticated user.
 */
interface FeatureManagerInterface
{
    /**
     * Check if a feature is accessible for the given subject/context.
     *
     * @param  string  $feature  The feature key/name
     * @param  mixed  $subject  Subject to check (User / Product / Team / Org / etc.; null = current authenticated user)
     * @param  mixed  $context  Additional context (subscription, plan, etc.)
     */
    public function canAccess(string $feature, mixed $subject = null, mixed $context = null): bool;

    /** Alias for canAccess. */
    public function isEnabled(string $feature, mixed $subject = null, mixed $context = null): bool;

    /** Alias for canAccess. */
    public function hasFeature(string $feature, mixed $subject = null, mixed $context = null): bool;

    /**
     * An explicit alias for canAccess, added in 0.11.0.
     *
     * `canAccess` answers ENTITLEMENT: whether the feature is granted, without
     * regard to remaining quota. This name says so at the call site, so nobody
     * has to re-read the implementation to find out which of the two questions
     * was being asked.
     */
    public function isEntitled(string $feature, mixed $subject = null, mixed $context = null): bool;

    /**
     * Entitled AND `$amount` fits in the remaining quota — the quota-aware read.
     *
     * A READ, not a gate: between this and the write that follows, another
     * request can spend the last unit. The subscription-scoped `Fms` service has
     * `tryIncrement()` for that; this class owns no storage and cannot.
     *
     * Unlimited (`remaining()` returning null) always allows.
     */
    public function canConsume(
        string $feature,
        mixed $subject = null,
        int $amount = 1,
        mixed $context = null,
    ): bool;

    /**
     * Get the remaining quantity for a resource-based feature.
     *
     * @return int|null Returns null if feature doesn't exist or isn't a resource feature
     */
    public function remaining(string $feature, mixed $subject = null, mixed $context = null): ?int;

    /**
     * Get all enabled features for a subject/context.
     *
     * @return array<int,string>
     */
    public function enabled(mixed $subject = null, mixed $context = null): array;
}
