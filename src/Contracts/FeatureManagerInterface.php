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
