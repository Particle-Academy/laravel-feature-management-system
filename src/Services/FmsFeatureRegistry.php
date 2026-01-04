<?php

namespace ParticleAcademy\Fms\Services;

/**
 * FmsFeatureRegistry service
 * Why: Central registry for code-defined FMS features (boolean and resource)
 * that can be synced into the `product_features` catalog and reused across
 * the application. Supports array, closure, and class-based definitions.
 */
class FmsFeatureRegistry
{
    /**
     * @var array<string, callable|string|array>
     */
    protected array $definitions = [];

    /**
     * Register a feature definition by key.
     * The definition may be:
     * - array: ['name' => string, 'description' => ?, 'type' => 'boolean|resource', 'config' => array]
     * - callable: fn (): array => [...]
     * - class-string: resolved from the container and must expose a definition() method.
     */
    public function register(string $key, callable|string|array $definition): void
    {
        $this->definitions[$key] = $definition;
    }

    /**
     * Return all raw definitions as registered.
     *
     * @return array<string, callable|string|array>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Resolve a single feature definition to a normalized array.
     *
     * @return array<string,mixed>|null
     */
    public function definition(string $key): ?array
    {
        $raw = $this->definitions[$key] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_callable($raw)) {
            return $raw();
        }

        if (is_string($raw) && class_exists($raw)) {
            $instance = app($raw);

            if (method_exists($instance, 'definition')) {
                return $instance->definition();
            }

            if (method_exists($raw, 'definition')) {
                return $raw::definition();
            }
        }

        return null;
    }

    /**
     * Sync only NEW features into the database (preserves existing customizations).
     * Why: Allows the fms:sync command to run on every deployment without
     * overwriting admin changes to feature names, descriptions, or defaults.
     *
     * @return array{created: int, skipped: int}
     */
    public function syncNewFeatures(): array
    {
        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            throw new \RuntimeException('FMS product_feature_model is not configured. Please set config("fms.product_feature_model") to your ProductFeature model class.');
        }
        
        $created = 0;
        $skipped = 0;

        foreach (array_keys($this->definitions) as $key) {
            $data = $this->definition($key);

            if (! is_array($data)) {
                continue;
            }

            // Check if feature already exists
            $exists = $productFeatureClass::where('key', $key)->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            // Create new feature
            $productFeatureClass::create([
                'key' => $key,
                'name' => $data['name'] ?? ucfirst(str_replace('-', ' ', $key)),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'boolean',
                'config' => $data['config'] ?? [],
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Force sync a specific feature by key (overwrites existing).
     * Why: Allows explicitly updating a feature when code changes require it,
     * while still preserving other features' admin customizations.
     */
    public function forceSyncFeature(string $key): bool
    {
        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            throw new \RuntimeException('FMS product_feature_model is not configured. Please set config("fms.product_feature_model") to your ProductFeature model class.');
        }
        
        $data = $this->definition($key);

        if (! is_array($data)) {
            return false;
        }

        $productFeatureClass::updateOrCreate(
            ['key' => $key],
            [
                'name' => $data['name'] ?? ucfirst(str_replace('-', ' ', $key)),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'boolean',
                'config' => $data['config'] ?? [],
            ],
        );

        return true;
    }

    /**
     * Sync all registered feature definitions into the ProductFeature table.
     * Uses the feature key as the unique identifier.
     *
     * @deprecated Use syncNewFeatures() for deployment-safe syncing or forceSyncFeature() for specific updates.
     */
    public function syncToDatabase(): int
    {
        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            throw new \RuntimeException('FMS product_feature_model is not configured. Please set config("fms.product_feature_model") to your ProductFeature model class.');
        }
        
        $count = 0;

        foreach (array_keys($this->definitions) as $key) {
            $data = $this->definition($key);

            if (! is_array($data)) {
                continue;
            }

            $productFeatureClass::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $data['name'] ?? ucfirst(str_replace('-', ' ', $key)),
                    'description' => $data['description'] ?? null,
                    'type' => $data['type'] ?? 'boolean',
                    'config' => $data['config'] ?? [],
                ],
            );

            $count++;
        }

        return $count;
    }
}

