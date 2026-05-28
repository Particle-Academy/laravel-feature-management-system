<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ParticleAcademy\Fms\Models\FeatureUsage;
use ParticleAcademy\Fms\Tests\TestCase;

uses(TestCase::class);

/*
 * Coverage for issue #3 — config-driven table names + self-skipping
 * create migration.
 *
 * The package test DB has neither `subscriptions` nor `product_features`,
 * so the create migration self-skips during the normal RefreshDatabase
 * boot. Each test below either asserts that skip behavior or builds the
 * prerequisite tables itself and runs the migration in isolation.
 */

function featureUsagesMigration(): object
{
    return require __DIR__.'/../../database/migrations/2024_01_01_000005_create_feature_usages_table.php';
}

function makeFkTargets(string $subscriptions = 'subscriptions', string $productFeatures = 'product_features'): void
{
    Schema::create($subscriptions, function (Blueprint $table) {
        $table->id();
    });
    Schema::create($productFeatures, function (Blueprint $table) {
        $table->ulid('id')->primary();
    });
}

it('reads the feature_usages table name from config', function () {
    config(['fms.tables.feature_usages' => 'fms_feature_usages']);

    expect((new FeatureUsage)->getTable())->toBe('fms_feature_usages');
});

it('falls back to the default table name when config is absent', function () {
    config(['fms.tables.feature_usages' => null]);

    expect((new FeatureUsage)->getTable())->toBe('feature_usages');
});

it('self-skips creating the table when FK target tables are absent', function () {
    Schema::dropIfExists('feature_usages');

    // subscriptions + product_features do not exist in the package test DB
    featureUsagesMigration()->up();

    expect(Schema::hasTable('feature_usages'))->toBeFalse();
});

it('creates the default table when FK targets exist', function () {
    Schema::dropIfExists('feature_usages');
    makeFkTargets();

    featureUsagesMigration()->up();

    expect(Schema::hasTable('feature_usages'))->toBeTrue()
        ->and(Schema::hasColumns('feature_usages', [
            'id', 'subscription_id', 'product_feature_id', 'used_quantity', 'period_start', 'period_end',
        ]))->toBeTrue();
});

it('creates a renamed/prefixed table honoring config', function () {
    config(['fms.tables.feature_usages' => 'fms_feature_usages']);
    Schema::dropIfExists('fms_feature_usages');
    makeFkTargets();

    featureUsagesMigration()->up();

    expect(Schema::hasTable('fms_feature_usages'))->toBeTrue()
        ->and(Schema::hasTable('feature_usages'))->toBeFalse();
});

it('points FKs at the configured subscription + product_feature tables', function () {
    config([
        'fms.tables.feature_usages' => 'catalog_feature_usages',
        'fms.tables.subscriptions' => 'billing_subscriptions',
        'fms.tables.product_features' => 'catalog_product_features',
    ]);
    Schema::dropIfExists('catalog_feature_usages');
    makeFkTargets('billing_subscriptions', 'catalog_product_features');

    // Would throw if the migration still referenced the hardcoded
    // 'subscriptions' / 'product_features' tables (they don't exist here).
    featureUsagesMigration()->up();

    expect(Schema::hasTable('catalog_feature_usages'))->toBeTrue();
});

it('leaves an already-present table untouched (forked-install case)', function () {
    config(['fms.tables.feature_usages' => 'preexisting_usages']);
    Schema::dropIfExists('preexisting_usages');
    Schema::create('preexisting_usages', function (Blueprint $table) {
        $table->id();
        $table->string('sentinel');
    });

    // No FK targets exist, but the table is already present -> skip, no throw.
    featureUsagesMigration()->up();

    expect(Schema::hasColumn('preexisting_usages', 'sentinel'))->toBeTrue();
});

it('down() drops the configured table name', function () {
    config(['fms.tables.feature_usages' => 'fms_feature_usages']);
    Schema::dropIfExists('fms_feature_usages');
    makeFkTargets();

    $migration = featureUsagesMigration();
    $migration->up();
    expect(Schema::hasTable('fms_feature_usages'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('fms_feature_usages'))->toBeFalse();
});
