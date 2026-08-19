<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ParticleAcademy\Fms\Database\FeatureUsagesSchema;
use ParticleAcademy\Fms\Tests\CommerceTestCase;
use ParticleAcademy\Fms\Tests\Fixtures\Commerce;
use ParticleAcademy\Fms\Tests\Fixtures\TestProduct;
use ParticleAcademy\Fms\Tests\Fixtures\TestProductFeature;

uses(CommerceTestCase::class);

/**
 * The reconcile migration, driven directly.
 *
 * It runs against live billing data, so the two behaviours that matter are what
 * it does when it CANNOT do the right thing safely: it must refuse, and it must
 * say how to proceed by hand. A migration that hopes is worse than one that
 * stops.
 */
function reconcileMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_08_19_000002_reconcile_feature_usages_subscription_key.php';
}

it('does nothing when the column already agrees with the host key', function () {
    $before = FeatureUsagesSchema::currentSubscriptionShape();

    reconcileMigration()->up();

    expect(FeatureUsagesSchema::currentSubscriptionShape())->toBe($before);
});

it('REFUSES to convert a populated table, and names the manual procedure', function () {
    // A metering row exists...
    $product = TestProduct::create(['name' => 'Pro']);
    $feature = TestProductFeature::create(['key' => 'ai-tokens', 'name' => 'Tokens', 'type' => 'resource']);
    DB::table(Commerce::SUBSCRIPTIONS)->insert([
        'id' => $sub = (string) Str::ulid(),
        'product_id' => $product->id,
        'owner_type' => 'x',
        'owner_id' => '1',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('feature_usages')->insert([
        'id' => (string) Str::ulid(),
        'subscription_id' => $sub,
        'product_feature_id' => $feature->id,
        'used_quantity' => 120,
        'overage_quantity' => 20,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ...and the resolved type is now something else entirely.
    config(['fms.subscription_key_type' => 'bigint']);

    reconcileMigration()->up();
})->throws(\RuntimeException::class, 'metering history');

it('rebuilds an empty table rather than leaving it mismatched', function () {
    expect(DB::table('feature_usages')->count())->toBe(0);

    config(['fms.subscription_key_type' => 'bigint']);

    reconcileMigration()->up();

    expect(FeatureUsagesSchema::currentSubscriptionShape()['type'])->toBe('bigint');
    expect(Schema::hasColumn('feature_usages', 'overage_quantity'))->toBeTrue();
});

it('rejects an unknown configured key type instead of silently defaulting', function () {
    config(['fms.subscription_key_type' => 'snowflake']);

    FeatureUsagesSchema::subscriptionKeyShape();
})->throws(\InvalidArgumentException::class, 'subscription_key_type');

it('leaves the table alone when there is no subscriptions table to reference', function () {
    config(['fms.tables.subscriptions' => 'nope_not_here']);
    config(['fms.subscription_key_type' => 'bigint']);

    $before = FeatureUsagesSchema::currentSubscriptionShape();

    reconcileMigration()->up();

    // Rebuilding from a guess would drop a working foreign key for nothing.
    expect(FeatureUsagesSchema::currentSubscriptionShape())->toBe($before);
});
