<?php

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use Illuminate\Contracts\Auth\Authenticatable;

if (!function_exists('feature')) {
    /**
     * Get the FeatureManager instance or check a specific feature.
     *
     * @param  string|null  $feature  The feature key to check (optional)
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return FeatureManagerInterface|bool
     */
    function feature(?string $feature = null, ?Authenticatable $user = null, mixed $context = null): FeatureManagerInterface|bool
    {
        $manager = app(FeatureManagerInterface::class);

        if ($feature === null) {
            return $manager;
        }

        return $manager->canAccess($feature, $user, $context);
    }
}

if (!function_exists('can_access_feature')) {
    /**
     * Check if a feature is accessible.
     *
     * @param  string  $feature  The feature key
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return bool
     */
    function can_access_feature(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        return app(FeatureManagerInterface::class)->canAccess($feature, $user, $context);
    }
}

if (!function_exists('has_feature')) {
    /**
     * Check if user has access to a feature.
     *
     * @param  string  $feature  The feature key
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return bool
     */
    function has_feature(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        return app(FeatureManagerInterface::class)->hasFeature($feature, $user, $context);
    }
}

if (!function_exists('feature_enabled')) {
    /**
     * Check if a feature is enabled.
     *
     * @param  string  $feature  The feature key
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return bool
     */
    function feature_enabled(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        return app(FeatureManagerInterface::class)->isEnabled($feature, $user, $context);
    }
}

if (!function_exists('feature_remaining')) {
    /**
     * Get remaining quantity for a resource feature.
     *
     * @param  string  $feature  The feature key
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return int|null
     */
    function feature_remaining(string $feature, ?Authenticatable $user = null, mixed $context = null): ?int
    {
        return app(FeatureManagerInterface::class)->remaining($feature, $user, $context);
    }
}

if (!function_exists('enabled_features')) {
    /**
     * Get all enabled features for a user/context.
     *
     * @param  Authenticatable|null  $user  The user to check for (optional)
     * @param  mixed  $context  Additional context (optional)
     * @return array<string>
     */
    function enabled_features(?Authenticatable $user = null, mixed $context = null): array
    {
        return app(FeatureManagerInterface::class)->enabled($user, $context);
    }
}

