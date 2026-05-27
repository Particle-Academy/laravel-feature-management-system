<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feature_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Link to the billing subscription
            // NOTE: Update 'subscriptions' table name if your subscription table differs
            $table->foreignId('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();

            $table->foreignUlid('product_feature_id')
                ->constrained('product_features')
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
        Schema::dropIfExists('feature_usages');
    }
};

