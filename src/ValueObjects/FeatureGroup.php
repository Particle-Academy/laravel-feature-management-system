<?php

namespace ParticleAcademy\Fms\ValueObjects;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Feature Group Value Object
 *
 * Why: A FeatureGroup bundles a set of features under a single key. Subjects
 * (User, Team, Org, Product, anything) get assigned to groups via the
 * polymorphic feature_group_assignments pivot. When the FeatureManager
 * resolves a feature for a subject, every group containing that feature
 * is OR'd into the result, and resource limits are taken as the MAX
 * across all enabled groups.
 *
 * Groups can also be enabled by a callable `enabled` gate (config-only,
 * no DB assignment) for things like "everyone subscribed to plan X" or
 * "users in cohort Y" — the polymorphic pivot is one path; the callable
 * is another. Both compose by OR.
 */
class FeatureGroup
{
    /**
     * @param  string  $key  Stable identifier (e.g. "pro-plan").
     * @param  string|null  $name  Human label.
     * @param  string|null  $description  Free-form description for admin/devtools.
     * @param  array<int,string>  $features  Feature keys included by this group.
     * @param  array<int,string>  $extends  Other group keys whose features (and overrides) merge in. One level only — no transitive expansion.
     * @param  array<string,array<string,mixed>>  $overrides  Per-feature overrides keyed by feature key. Today supports `limit` (max wins). e.g. `['ai-tokens' => ['limit' => 50000]]`
     * @param  (callable(?Authenticatable,mixed):bool)|bool|null  $enabled  Optional gate. If provided and truthy, the group is considered enabled for the subject regardless of pivot assignment. If omitted, the group is enabled only when explicitly assigned via the pivot.
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly array $features = [],
        public readonly array $extends = [],
        public readonly array $overrides = [],
        public readonly mixed $enabled = null,
    ) {}

    /**
     * Build from a `config/fms.php` entry.
     *
     * @param  array<string,mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            name: $config['name'] ?? null,
            description: $config['description'] ?? null,
            features: array_values($config['features'] ?? []),
            extends: array_values($config['extends'] ?? []),
            overrides: $config['overrides'] ?? [],
            enabled: $config['enabled'] ?? null,
        );
    }

    /** Whether this group's `enabled` callable resolves true for the subject. */
    public function isEnabledByCallable(?Authenticatable $user, mixed $context): bool
    {
        if ($this->enabled === null) {
            return false;
        }
        if (is_bool($this->enabled)) {
            return $this->enabled;
        }
        if (is_callable($this->enabled)) {
            return (bool) call_user_func($this->enabled, $user, $context);
        }
        return false;
    }

    /** Override value for a given feature key, or null if no override set. */
    public function overrideFor(string $featureKey): ?array
    {
        return $this->overrides[$featureKey] ?? null;
    }
}
