<?php

namespace ParticleAcademy\Fms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Feature Manager Interface
 * Why: Defines the contract for feature access control, allowing different
 * implementations and strategies to be used interchangeably.
 */
interface FeatureManagerInterface
{
    /**
     * Check if a feature is accessible for the given user/context.
     *
     * @param  string  $feature  The feature key/name
     * @param  Authenticatable|null  $user  The user to check access for (null = current user)
     * @param  mixed  $context  Additional context (subscription, plan, etc.)
     * @return bool
     */
    public function canAccess(string $feature, ?Authenticatable $user = null, mixed $context = null): bool;

    /**
     * Check if a feature is enabled (simple boolean check).
     *
     * @param  string  $feature  The feature key/name
     * @param  Authenticatable|null  $user  The user to check for
     * @param  mixed  $context  Additional context
     * @return bool
     */
    public function isEnabled(string $feature, ?Authenticatable $user = null, mixed $context = null): bool;

    /**
     * Check if user has access to a feature (alias for canAccess).
     *
     * @param  string  $feature  The feature key/name
     * @param  Authenticatable|null  $user  The user to check for
     * @param  mixed  $context  Additional context
     * @return bool
     */
    public function hasFeature(string $feature, ?Authenticatable $user = null, mixed $context = null): bool;

    /**
     * Get the remaining quantity for a resource-based feature.
     *
     * @param  string  $feature  The feature key/name
     * @param  Authenticatable|null  $user  The user to check for
     * @param  mixed  $context  Additional context
     * @return int|null Returns null if feature doesn't exist or isn't a resource feature
     */
    public function remaining(string $feature, ?Authenticatable $user = null, mixed $context = null): ?int;

    /**
     * Get all enabled features for a user/context.
     *
     * @param  Authenticatable|null  $user  The user to check for
     * @param  mixed  $context  Additional context
     * @return array<string>
     */
    public function enabled(?Authenticatable $user = null, mixed $context = null): array;
}

