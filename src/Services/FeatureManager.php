<?php

namespace ParticleAcademy\Fms\Services;

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Feature Manager Service
 * Why: Core service for checking feature access using multiple strategies:
 * config-based, registry-based, gate/policy checks, and database lookups.
 */
class FeatureManager implements FeatureManagerInterface
{
    public function __construct(
        protected FmsFeatureRegistry $registry
    ) {}

    /**
     * Check if a feature is accessible for the given user/context.
     */
    public function canAccess(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        $user = $user ?? Auth::user();

        // Strategy 1: Check Laravel Gate/Policy if defined
        if (Gate::has($feature)) {
            return Gate::forUser($user)->allows($feature, $context);
        }

        // Strategy 2: Check feature registry definition
        $definition = $this->registry->definition($feature);
        if ($definition !== null) {
            return $this->checkDefinition($definition, $user, $context);
        }

        // Strategy 3: Check config file
        $configValue = config("fms.features.{$feature}.enabled", null);
        if ($configValue !== null) {
            return $this->evaluateConfigValue($configValue, $user, $context);
        }

        // Strategy 4: Check database if FeatureUsage model exists
        if ($this->hasDatabaseSupport()) {
            return $this->checkDatabaseFeature($feature, $user, $context);
        }

        // Default: feature not found, deny access
        return false;
    }

    /**
     * Check if a feature is enabled (simple boolean check).
     */
    public function isEnabled(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $user, $context);
    }

    /**
     * Check if user has access to a feature (alias for canAccess).
     */
    public function hasFeature(string $feature, ?Authenticatable $user = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $user, $context);
    }

    /**
     * Get the remaining quantity for a resource-based feature.
     */
    public function remaining(string $feature, ?Authenticatable $user = null, mixed $context = null): ?int
    {
        $user = $user ?? Auth::user();

        // Check registry definition first
        $definition = $this->registry->definition($feature);
        if ($definition !== null && ($definition['type'] ?? null) === 'resource') {
            return $this->getResourceRemaining($definition, $feature, $user, $context);
        }

        // Check config
        $config = config("fms.features.{$feature}", null);
        if ($config !== null && ($config['type'] ?? null) === 'resource') {
            return $this->getResourceRemaining($config, $feature, $user, $context);
        }

        // Check database if available
        if ($this->hasDatabaseSupport()) {
            return $this->getDatabaseResourceRemaining($feature, $user, $context);
        }

        return null;
    }

    /**
     * Get all enabled features for a user/context.
     */
    public function enabled(?Authenticatable $user = null, mixed $context = null): array
    {
        $user = $user ?? Auth::user();
        $enabled = [];

        // Check registry features
        foreach (array_keys($this->registry->all()) as $feature) {
            if ($this->canAccess($feature, $user, $context)) {
                $enabled[] = $feature;
            }
        }

        // Check config features
        $configFeatures = config('fms.features', []);
        foreach (array_keys($configFeatures) as $feature) {
            if (!in_array($feature, $enabled) && $this->canAccess($feature, $user, $context)) {
                $enabled[] = $feature;
            }
        }

        return array_unique($enabled);
    }

    /**
     * Check a feature definition against user/context.
     */
    protected function checkDefinition(array $definition, ?Authenticatable $user, mixed $context): bool
    {
        // If definition has a closure/callable check
        if (isset($definition['check']) && is_callable($definition['check'])) {
            return (bool) call_user_func($definition['check'], $user, $context);
        }

        // If definition has enabled flag
        if (isset($definition['enabled'])) {
            return $this->evaluateConfigValue($definition['enabled'], $user, $context);
        }

        // Default: enabled if definition exists
        return true;
    }

    /**
     * Evaluate a config value (can be boolean, closure, or callable).
     */
    protected function evaluateConfigValue(mixed $value, ?Authenticatable $user, mixed $context): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_callable($value)) {
            return (bool) call_user_func($value, $user, $context);
        }

        return false;
    }

    /**
     * Get remaining quantity for a resource feature.
     */
    protected function getResourceRemaining(array $definition, string $feature, ?Authenticatable $user, mixed $context): ?int
    {
        // Check if definition has a remaining callback
        if (isset($definition['remaining']) && is_callable($definition['remaining'])) {
            return call_user_func($definition['remaining'], $feature, $user, $context);
        }

        // Check config for limit
        $limit = $definition['limit'] ?? config("fms.features.{$feature}.limit", null);
        if ($limit === null) {
            return null;
        }

        // Get usage if available
        $usage = $this->getResourceUsage($definition, $feature, $user, $context);

        return max(0, (int) $limit - (int) $usage);
    }

    /**
     * Get current usage for a resource feature.
     */
    protected function getResourceUsage(array $definition, string $feature, ?Authenticatable $user, mixed $context): int
    {
        // Check if definition has a usage callback
        if (isset($definition['usage']) && is_callable($definition['usage'])) {
            return (int) call_user_func($definition['usage'], $feature, $user, $context);
        }

        // Check database if available
        if ($this->hasDatabaseSupport()) {
            return $this->getDatabaseResourceUsage($feature, $user, $context);
        }

        return 0;
    }

    /**
     * Check if database support is available (FeatureUsage model exists).
     */
    protected function hasDatabaseSupport(): bool
    {
        return class_exists(\ParticleAcademy\Fms\Models\FeatureUsage::class);
    }

    /**
     * Check feature access via database.
     */
    protected function checkDatabaseFeature(string $feature, ?Authenticatable $user, mixed $context): bool
    {
        // This would require integration with a subscription/plan system
        // For now, return false - can be extended by applications
        return false;
    }

    /**
     * Get remaining quantity from database.
     */
    protected function getDatabaseResourceRemaining(string $feature, ?Authenticatable $user, mixed $context): ?int
    {
        // This would require integration with a subscription/plan system
        // For now, return null - can be extended by applications
        return null;
    }

    /**
     * Get usage from database.
     */
    protected function getDatabaseResourceUsage(string $feature, ?Authenticatable $user, mixed $context): int
    {
        // This would require integration with a subscription/plan system
        // For now, return 0 - can be extended by applications
        return 0;
    }
}

