<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `feature_usages` table definition, in one place.
 *
 * Two migrations need it — the one that creates the table and the one that
 * reconciles a `subscription_id` whose type no longer matches the host's
 * subscriptions table — and a schema that lives in two migration files is a
 * schema that will disagree with itself. It is also the only way to unit-test
 * the key-type detection without running a migration.
 *
 * ## Why the subscription key type is not a constant
 *
 * It used to be: `foreignId('subscription_id')`, a bigint, because the first
 * application this package ran in had auto-incrementing subscriptions. But
 * `particle-academy/laravel-catalog` — the package FMS is paired with — is ULIDs
 * throughout, and subscriptions belong to the HOST, not to FMS. In a ULID-native
 * app the create migration did not degrade, it failed: a bigint foreign key
 * cannot reference a `char(26)` primary key.
 *
 * So the package stops assuming and matches what is actually there:
 *
 *   1. `config('fms.subscription_key_type')` — `bigint`, `ulid`, `uuid` or
 *      `string`. Explicit always wins, and this is the documented route.
 *   2. Detection from the referenced table's key column.
 *   3. `bigint` when the referenced table is not there to look at — the
 *      historical default, and the create migration self-skips in that case
 *      anyway.
 *
 * ## SQLite cannot be detected past "is it a string"
 *
 * `Schema::getColumns()` on SQLite reports `ulid`, `uuid` and `string(64)` all
 * as bare `varchar` with no length. That is harmless there — SQLite does not
 * enforce foreign-key type compatibility — but it is why the config override is
 * documented first rather than as a fallback. MySQL and Postgres report the
 * length, and MySQL is the driver that rejects a mismatched foreign key.
 */
final class FeatureUsagesSchema
{
    /** @var list<string> */
    public const KEY_TYPES = ['bigint', 'ulid', 'uuid', 'string'];

    public static function usagesTable(): string
    {
        return config('fms.tables.feature_usages') ?? 'feature_usages';
    }

    public static function subscriptionsTable(): string
    {
        return config('fms.tables.subscriptions') ?? 'subscriptions';
    }

    public static function productFeaturesTable(): string
    {
        return config('fms.tables.product_features') ?? 'product_features';
    }

    /** The referenced column on the subscriptions table. */
    public static function subscriptionKey(): string
    {
        return config('fms.subscription_key') ?? 'id';
    }

    /**
     * The resolved shape of `feature_usages.subscription_id`.
     *
     * @return array{type:string, length:?int}
     */
    public static function subscriptionKeyShape(): array
    {
        $configured = config('fms.subscription_key_type');

        if (is_string($configured) && $configured !== '') {
            if (! in_array($configured, self::KEY_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "fms.subscription_key_type must be one of: ".implode(', ', self::KEY_TYPES)
                    .". Got '{$configured}'."
                );
            }

            return ['type' => $configured, 'length' => null];
        }

        $detected = self::detect(self::subscriptionsTable(), self::subscriptionKey());

        return $detected ?? ['type' => 'bigint', 'length' => null];
    }

    /**
     * Read a column's shape off a live table.
     *
     * Returns null when the table or column is absent, which is a legitimate
     * state here: the create migration deliberately runs before the host's own
     * subscriptions migration in some layouts.
     *
     * @return array{type:string, length:?int}|null
     */
    public static function detect(string $table, string $column): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        foreach (Schema::getColumns($table) as $definition) {
            if (($definition['name'] ?? null) !== $column) {
                continue;
            }

            return self::classify(
                (string) ($definition['type_name'] ?? ''),
                (string) ($definition['type'] ?? ''),
            );
        }

        return null;
    }

    /**
     * Map a driver's reported type onto one of `KEY_TYPES`.
     *
     * `$typeName` is the bare name (`bigint`, `varchar`, `uuid`); `$type` is the
     * full declaration and is the only place a length appears (`char(26)`).
     *
     * @return array{type:string, length:?int}
     */
    public static function classify(string $typeName, string $type = ''): array
    {
        $name = strtolower(trim($typeName));
        $full = strtolower(trim($type !== '' ? $type : $typeName));

        $integers = [
            'bigint', 'int8', 'integer', 'int', 'int4', 'mediumint',
            'smallint', 'int2', 'tinyint', 'serial', 'bigserial', 'numeric',
        ];

        if (in_array($name, $integers, true)) {
            return ['type' => 'bigint', 'length' => null];
        }

        if ($name === 'uuid') {
            return ['type' => 'uuid', 'length' => null];
        }

        $length = null;
        if (preg_match('/\((\d+)\)/', $full, $matches) === 1) {
            $length = (int) $matches[1];
        }

        return match ($length) {
            26 => ['type' => 'ulid', 'length' => 26],
            36 => ['type' => 'uuid', 'length' => 36],
            // A string of some other (or unknown) width. Carrying the detected
            // length is what keeps a MySQL foreign key valid; a null length
            // means the driver did not say, which is SQLite, which does not care.
            default => ['type' => 'string', 'length' => $length],
        };
    }

    /**
     * Should the create migration run?
     *
     * It self-skips (no error) when the table is already present — an older fork
     * under a different name, say — or when either foreign-key target is absent
     * at apply time. The second guard is what lets this migration sit harmlessly
     * early in a consumer's chronological order; they can build the real table
     * later in their own migration if their targets come after.
     */
    public static function shouldCreate(): bool
    {
        return ! Schema::hasTable(self::usagesTable())
            && Schema::hasTable(self::subscriptionsTable())
            && Schema::hasTable(self::productFeaturesTable());
    }

    /** Create `feature_usages` with a `subscription_id` matching the host's key. */
    public static function create(): void
    {
        $usages = self::usagesTable();
        $subscriptions = self::subscriptionsTable();
        $productFeatures = self::productFeaturesTable();
        $shape = self::subscriptionKeyShape();
        $referencedKey = self::subscriptionKey();

        Schema::create($usages, function (Blueprint $table) use ($subscriptions, $productFeatures, $shape, $referencedKey) {
            $table->ulid('id')->primary();

            self::subscriptionColumn($table, $shape);

            $table->foreignUlid('product_feature_id')
                ->constrained($productFeatures)
                ->cascadeOnDelete();

            // Total used quantity for the current period (e.g. tokens, seats).
            $table->unsignedBigInteger('used_quantity')->default(0);

            // The BILLABLE part of used_quantity: consumption past the plan's
            // included quantity, permitted up to `product_feature_configs.
            // overage_limit`. Recorded rather than derived, because a mid-period
            // plan upgrade raises the included quantity and would silently erase
            // overage that was genuinely incurred — possibly already invoiced.
            $table->unsignedBigInteger('overage_quantity')->default(0);

            // Optional period bounds to support metering per billing cycle.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamps();

            $table->foreign('subscription_id')
                ->references($referencedKey)
                ->on($subscriptions)
                ->cascadeOnDelete();

            $table->unique(
                ['subscription_id', 'product_feature_id', 'period_start'],
                'feature_usage_subscription_feature_period_unique'
            );
        });
    }

    /**
     * Add the `subscription_id` column in the resolved shape.
     *
     * The foreign key is declared separately by the caller: `foreignId()` and
     * friends would add their own, and this has to reference a configurable
     * column name on a configurable table.
     *
     * @param  array{type:string, length:?int}  $shape
     */
    public static function subscriptionColumn(Blueprint $table, array $shape): void
    {
        match ($shape['type']) {
            'bigint' => $table->unsignedBigInteger('subscription_id'),
            'ulid' => $table->char('subscription_id', 26),
            'uuid' => $table->uuid('subscription_id'),
            default => $shape['length'] !== null
                ? $table->string('subscription_id', $shape['length'])
                : $table->string('subscription_id'),
        };
    }

    /**
     * The shape `feature_usages.subscription_id` currently has, or null when the
     * table or column is absent.
     *
     * @return array{type:string, length:?int}|null
     */
    public static function currentSubscriptionShape(): ?array
    {
        return self::detect(self::usagesTable(), 'subscription_id');
    }
}
