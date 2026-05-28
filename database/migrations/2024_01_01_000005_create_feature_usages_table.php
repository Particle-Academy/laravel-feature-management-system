<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Table names are config-driven (config/fms.php → `tables`) so apps
     * with prefixed or renamed schemas don't have to fork this file.
     *
     * The migration self-skips (no error) when:
     *   - the feature_usages table already exists (e.g. created by an
     *     older fork under a different/prefixed name), or
     *   - either FK target table is missing at apply time.
     * The second guard lets this migration sit harmlessly early in a
     * consumer's chronological migration order — they can build the real
     * table later in their own migration if their FK targets come after.
     */
    public function up(): void
    {
        $usagesTable = config('fms.tables.feature_usages') ?? 'feature_usages';
        $subscriptionsTable = config('fms.tables.subscriptions') ?? 'subscriptions';
        $productFeaturesTable = config('fms.tables.product_features') ?? 'product_features';

        if (Schema::hasTable($usagesTable)) {
            return; // already present (or a renamed/forked install) — leave it alone
        }

        if (! Schema::hasTable($subscriptionsTable) || ! Schema::hasTable($productFeaturesTable)) {
            return; // FK prerequisites absent — defer to the consumer's own migration
        }

        Schema::create($usagesTable, function (Blueprint $table) use ($subscriptionsTable, $productFeaturesTable) {
            $table->ulid('id')->primary();

            // Link to the billing subscription (table name from config).
            $table->foreignId('subscription_id')
                ->constrained($subscriptionsTable)
                ->cascadeOnDelete();

            $table->foreignUlid('product_feature_id')
                ->constrained($productFeaturesTable)
                ->cascadeOnDelete();

            // Total used quantity for the current period (e.g. tokens, seats)
            $table->unsignedBigInteger('used_quantity')->default(0);

            // Optional period bounds to support metering per billing cycle
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'product_feature_id', 'period_start'], 'feature_usage_subscription_feature_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('fms.tables.feature_usages') ?? 'feature_usages');
    }
};
