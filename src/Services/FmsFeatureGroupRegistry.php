<?php

namespace ParticleAcademy\Fms\Services;

use ParticleAcademy\Fms\ValueObjects\FeatureGroup;
use RuntimeException;

/**
 * Feature Group Registry
 *
 * Why: Holds FeatureGroup value objects keyed by string. Resolves the
 * "extends" chain at registration time (one level deep, with cycle
 * detection) so consumers see a flat set of features per group.
 *
 * Groups can be defined in config/fms.php under the `groups` key, or
 * registered programmatically via `register()` for app-level grouping
 * (e.g. inside a service provider).
 */
class FmsFeatureGroupRegistry
{
    /** @var array<string,FeatureGroup> */
    protected array $groups = [];

    /**
     * Resolved feature lists (after extends merging) cached to avoid
     * repeated traversal. Cleared whenever `register()` mutates.
     *
     * @var array<string,array<int,string>>
     */
    protected array $resolvedFeaturesCache = [];

    /**
     * Resolved override maps (after extends merging) cached.
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    protected array $resolvedOverridesCache = [];

    public function register(FeatureGroup $group): void
    {
        $this->groups[$group->key] = $group;
        $this->resolvedFeaturesCache = [];
        $this->resolvedOverridesCache = [];
    }

    public function has(string $key): bool
    {
        return isset($this->groups[$key]);
    }

    public function get(string $key): ?FeatureGroup
    {
        return $this->groups[$key] ?? null;
    }

    /** @return array<string,FeatureGroup> */
    public function all(): array
    {
        return $this->groups;
    }

    /**
     * Resolved feature list for a group (own features + features from all
     * extended groups). Caches per-key for repeated lookups.
     *
     * @return array<int,string>
     */
    public function resolvedFeatures(string $key): array
    {
        if (isset($this->resolvedFeaturesCache[$key])) {
            return $this->resolvedFeaturesCache[$key];
        }

        $group = $this->groups[$key] ?? null;
        if (!$group) {
            return [];
        }

        $features = $group->features;
        foreach ($group->extends as $extKey) {
            $this->guardCycle($key, $extKey);
            $extGroup = $this->groups[$extKey] ?? null;
            if (!$extGroup) {
                continue;
            }
            // One level deep — no transitive expansion.
            $features = array_merge($features, $extGroup->features);
        }

        $features = array_values(array_unique($features));
        $this->resolvedFeaturesCache[$key] = $features;
        return $features;
    }

    /**
     * Resolved overrides for a group, merged from extends. Own overrides
     * win over extended ones (closer-to-the-leaf wins).
     *
     * @return array<string,array<string,mixed>>
     */
    public function resolvedOverrides(string $key): array
    {
        if (isset($this->resolvedOverridesCache[$key])) {
            return $this->resolvedOverridesCache[$key];
        }

        $group = $this->groups[$key] ?? null;
        if (!$group) {
            return [];
        }

        $overrides = [];
        foreach ($group->extends as $extKey) {
            $this->guardCycle($key, $extKey);
            $extGroup = $this->groups[$extKey] ?? null;
            if (!$extGroup) {
                continue;
            }
            $overrides = array_replace_recursive($overrides, $extGroup->overrides);
        }
        // Own overrides win.
        $overrides = array_replace_recursive($overrides, $group->overrides);

        $this->resolvedOverridesCache[$key] = $overrides;
        return $overrides;
    }

    /**
     * Returns all group keys whose resolved feature list contains $feature.
     *
     * @return array<int,string>
     */
    public function groupsContaining(string $feature): array
    {
        $matching = [];
        foreach ($this->groups as $key => $_group) {
            if (in_array($feature, $this->resolvedFeatures($key), true)) {
                $matching[] = $key;
            }
        }
        return $matching;
    }

    /**
     * One-level cycle guard. Prevents `pro-plan` from extending itself or
     * any extends-target from extending back to the source.
     */
    protected function guardCycle(string $sourceKey, string $extKey): void
    {
        if ($sourceKey === $extKey) {
            throw new RuntimeException("[fms] feature group `{$sourceKey}` cannot extend itself");
        }
        $extGroup = $this->groups[$extKey] ?? null;
        if (!$extGroup) {
            return;
        }
        if (in_array($sourceKey, $extGroup->extends, true)) {
            throw new RuntimeException(
                "[fms] feature group cycle detected: `{$sourceKey}` and `{$extKey}` extend each other"
            );
        }
    }
}
