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
 *   1. Gate / Policy
 *   2. Registry (FmsFeatureRegistry)
 *   3. Feature Groups (FmsFeatureGroupRegistry) — OR'd across all
 *      groups enabled for the subject (via pivot or callable gate)
 *   4. Config (config/fms.php features.{key}.enabled)
 *   5. Database (subclass extension hook)
 *
 * Resource limits aggregate as MAX across all enabled groups containing
 * the feature, then fall back to the feature's own limit.
 */
class FeatureManager implements FeatureManagerInterface
{
    public function __construct(
        protected FmsFeatureRegistry $registry,
        protected ?FmsFeatureGroupRegistry $groupRegistry = null,
    ) {}

    public function canAccess(string $feature, mixed $user = null, mixed $context = null): bool
    {
        $user = $user ?? Auth::user();

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

    public function remaining(string $feature, mixed $user = null, mixed $context = null): ?int
    {
        $user = $user ?? Auth::user();

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
            return call_user_func($definition['remaining'], $feature, $user, $context);
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
            return (int) call_user_func($definition['usage'], $feature, $user, $context);
        }
        if ($this->hasDatabaseSupport()) {
            return $this->getDatabaseResourceUsage($feature, $user, $context);
        }
        return 0;
    }

    protected function hasDatabaseSupport(): bool
    {
        return class_exists(\ParticleAcademy\Fms\Models\FeatureUsage::class);
    }

    protected function checkDatabaseFeature(string $feature, mixed $user, mixed $context): bool
    {
        return false;
    }

    protected function getDatabaseResourceRemaining(string $feature, mixed $user, mixed $context): ?int
    {
        return null;
    }

    protected function getDatabaseResourceUsage(string $feature, mixed $user, mixed $context): int
    {
        return 0;
    }
}
