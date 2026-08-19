<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commerce fixtures `Fms` actually needs, in the shape it actually needs.
 *
 * `Fms` walks subscription -> product -> `product_feature_configs` pivot and
 * meters against `feature_usages`, and **nothing tested that walk end to end
 * until 0.11.0.** Every existing test either exercised `FeatureManager` (a
 * different class, with no storage) or a static helper. That is exactly why a
 * hard-coded `\App\Models\BillingSubscription` made `can()` return false for
 * every consumer of the package and nobody noticed until a Python port read the
 * source: the one code path that was broken was the one code path with no test
 * through it.
 *
 * The subscriptions here are **ULID-keyed on purpose**. `laravel-catalog` is
 * ULIDs throughout and FMS declared a bigint `subscription_id`, so the pairing
 * these fixtures represent is precisely the one the schema used to make
 * impossible.
 */
final class Commerce
{
    public const PRODUCTS = 'test_products';

    public const FEATURES = 'test_product_features';

    public const CONFIGS = 'test_product_feature_configs';

    public const SUBSCRIPTIONS = 'test_subscriptions';

    /**
     * Build the host-owned tables FMS's foreign keys point at.
     *
     * Called before the package migrations, because the create migration
     * self-skips when either target is absent — the guard that lets it sit early
     * in a consumer's chronological order.
     */
    public static function migrate(): void
    {
        Schema::create(self::PRODUCTS, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create(self::FEATURES, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type')->default('boolean');
            $table->timestamps();
        });

        Schema::create(self::CONFIGS, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained(self::PRODUCTS)->cascadeOnDelete();
            $table->foreignUlid('product_feature_id')->constrained(self::FEATURES)->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('included_quantity')->nullable();
            $table->unsignedBigInteger('overage_limit')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'product_feature_id']);
        });

        Schema::create(self::SUBSCRIPTIONS, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained(self::PRODUCTS)->cascadeOnDelete();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('status')->default('active');
            $table->timestamp('renews_at')->nullable();
            $table->timestamps();
        });
    }
}
