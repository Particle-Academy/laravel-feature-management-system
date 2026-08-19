<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Feature;

use Illuminate\Support\Facades\Event;
use ParticleAcademy\Fms\Events\FeatureOverageRecorded;
use ParticleAcademy\Fms\Services\Fms;
use ParticleAcademy\Fms\Tests\CommerceTestCase;
use ParticleAcademy\Fms\Tests\Fixtures\TestBillingSubscription;
use ParticleAcademy\Fms\Tests\Fixtures\TestProduct;
use ParticleAcademy\Fms\Tests\Fixtures\TestProductFeature;
use ParticleAcademy\Fms\Tests\Fixtures\TestUser;

uses(CommerceTestCase::class);

/**
 * `Fms`, driven end to end through a real product -> subscription -> pivot.
 *
 * **There was no such test until 0.11.0**, and that is not a coincidental gap:
 * the one code path with no test through it is the one that shipped hard-coding
 * `\App\Models\BillingSubscription`, so `can()` returned false for every
 * consumer of the package and nobody noticed until a Python port read the
 * source. Every existing test drove `FeatureManager` — a different class, with
 * no storage — or a static helper.
 *
 * The assertion that matters most is the plainest one here: a configured,
 * entitled feature answers TRUE. That is the assertion whose absence hid a total
 * denial.
 */
function seedPlan(array $features = []): array
{
    $product = TestProduct::create(['name' => 'Pro']);

    foreach ($features as $key => $spec) {
        $feature = TestProductFeature::create([
            'key' => $key,
            'name' => ucfirst($key),
            'type' => $spec['type'] ?? 'boolean',
        ]);

        $product->productFeatures()->attach($feature->id, [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'enabled' => $spec['enabled'] ?? true,
            'included_quantity' => $spec['included_quantity'] ?? null,
            'overage_limit' => $spec['overage_limit'] ?? null,
        ]);
    }

    $user = TestUser::create(['name' => 'Ada']);

    $subscription = TestBillingSubscription::create([
        'product_id' => $product->id,
        'owner_type' => TestUser::class,
        'owner_id' => (string) $user->id,
        'status' => 'active',
        'renews_at' => now()->addDays(10),
    ]);

    return [$product, $subscription, $user];
}

function fms(): Fms
{
    return new Fms;
}

// ---- The walk itself ----------------------------------------------------

it('grants a configured boolean feature — the assertion whose absence hid a total denial', function () {
    [, $subscription] = seedPlan(['use-mcp' => ['type' => 'boolean', 'enabled' => true]]);

    expect(fms()->can('use-mcp', $subscription))->toBeTrue();
});

it('denies a boolean feature the plan does not enable', function () {
    [, $subscription] = seedPlan(['use-mcp' => ['type' => 'boolean', 'enabled' => false]]);

    expect(fms()->can('use-mcp', $subscription))->toBeFalse();
});

it('denies a feature the plan does not carry at all, and records the message', function () {
    [, $subscription] = seedPlan(['use-mcp' => ['type' => 'boolean']]);

    $service = fms();

    expect($service->can('sso', $subscription, 'Upgrade for SSO'))->toBeFalse();
    expect($service->lastError())->toBe('Upgrade for SSO');
});

it('resolves the subscription from a bare user, not just an explicit scope', function () {
    [, , $user] = seedPlan(['use-mcp' => ['type' => 'boolean', 'enabled' => true]]);

    // The path that was broken: a User instance had to match a hard-coded
    // application class before any subscription was looked up.
    expect(fms()->can('use-mcp', $user))->toBeTrue();
});

it('meters against a ULID-keyed subscription', function () {
    // laravel-catalog is ULIDs throughout and FMS declared a bigint
    // subscription_id, so this pairing used to be unrepresentable.
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100],
    ]);

    expect(fms()->increment('ai-tokens', 40, $subscription))->toBeTrue();
    expect(fms()->usage('ai-tokens', $subscription))->toBe(40);
    expect(fms()->remaining('ai-tokens', $subscription))->toBe(60);
});

// ---- Ruling 1: entitlement is not quota ---------------------------------

it('keeps a resource feature ENTITLED when the allowance is exhausted', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100],
    ]);

    fms()->increment('ai-tokens', 100, $subscription);

    expect(fms()->remaining('ai-tokens', $subscription))->toBe(0);
    // Before 0.11.0 this was false. It is the behaviour change that silently
    // over-permits anyone using can() as a consumption gate.
    expect(fms()->can('ai-tokens', $subscription))->toBeTrue();
    expect(fms()->isEntitled('ai-tokens', $subscription))->toBeTrue();
});

it('refuses consumption at an exhausted allowance — what can() used to answer', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100],
    ]);

    fms()->increment('ai-tokens', 100, $subscription);

    expect(fms()->canConsume('ai-tokens', 1, $subscription))->toBeFalse();
    expect(fms()->tryIncrement('ai-tokens', 1, $subscription))->toBeFalse();
});

it('permits consumption exactly up to the included quantity', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100],
    ]);

    fms()->increment('ai-tokens', 90, $subscription);

    // <=, not <. A plan that says 100 must permit the hundredth unit.
    expect(fms()->canConsume('ai-tokens', 10, $subscription))->toBeTrue();
    expect(fms()->canConsume('ai-tokens', 11, $subscription))->toBeFalse();
});

it('requires the pivot enabled flag on a resource feature, like the Node and Python twins', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'enabled' => false, 'included_quantity' => 100],
    ]);

    // Before 0.11.0, can() ignored `enabled` for resource features entirely and
    // looked only at remaining quota — so a disabled row with an allowance was
    // treated as ON, while every other runtime read it as off.
    expect(fms()->can('ai-tokens', $subscription))->toBeFalse();
    expect(fms()->canConsume('ai-tokens', 1, $subscription))->toBeFalse();
    expect(fms()->tryIncrement('ai-tokens', 1, $subscription))->toBeFalse();
});

it('treats a null included quantity as unlimited', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => null],
    ]);

    expect(fms()->remaining('ai-tokens', $subscription))->toBeNull();
    expect(fms()->canConsume('ai-tokens', 1_000_000, $subscription))->toBeTrue();
    expect(fms()->tryIncrement('ai-tokens', 1_000_000, $subscription))->toBeTrue();
    // Unlimited is not unmetered.
    expect(fms()->usage('ai-tokens', $subscription))->toBe(1_000_000);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(0);
});

// ---- Ruling 2: billable overage ----------------------------------------

it('refuses consumption past the included quantity when no overage is configured', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => null],
    ]);

    fms()->increment('ai-tokens', 100, $subscription);

    // Null overage_limit means NO overage. Every row written before 0.11.0 is
    // null, so this is what keeps the ruling opt-in.
    expect(fms()->tryIncrement('ai-tokens', 1, $subscription))->toBeFalse();
    expect(fms()->overage('ai-tokens', $subscription))->toBe(0);
});

it('permits and records consumption inside the overage band', function () {
    Event::fake([FeatureOverageRecorded::class]);

    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    expect(fms()->tryIncrement('ai-tokens', 90, $subscription))->toBeTrue();
    expect(fms()->overage('ai-tokens', $subscription))->toBe(0);

    // Straddles the included line: 10 of these 30 are free, 20 are billable.
    expect(fms()->tryIncrement('ai-tokens', 30, $subscription))->toBeTrue();
    expect(fms()->usage('ai-tokens', $subscription))->toBe(120);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(20);

    // Already above the line: all 10 are billable, and the 20 already recorded
    // are NOT re-billed.
    expect(fms()->tryIncrement('ai-tokens', 10, $subscription))->toBeTrue();
    expect(fms()->overage('ai-tokens', $subscription))->toBe(30);

    Event::assertDispatchedTimes(FeatureOverageRecorded::class, 2);
    Event::assertDispatched(
        FeatureOverageRecorded::class,
        fn (FeatureOverageRecorded $e): bool => $e->featureKey === 'ai-tokens'
            && $e->units === 20
            && $e->totalUnits === 20,
    );
});

it('enforces the end of the overage band', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    fms()->increment('ai-tokens', 150, $subscription);

    // overage_limit is a ceiling, not an alert.
    expect(fms()->canConsume('ai-tokens', 1, $subscription))->toBeFalse();
    expect(fms()->tryIncrement('ai-tokens', 1, $subscription))->toBeFalse();
    expect(fms()->overage('ai-tokens', $subscription))->toBe(50);
});

it('unwinds only the billable part of a refund', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    fms()->increment('ai-tokens', 110, $subscription);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(10);

    // Refunding 30 units when only 10 of them were ever billable must credit 10,
    // not 30 — a naive symmetric decrement would leave overage at -20 (clamped
    // to 0) and under-bill the next consumption.
    fms()->decrement('ai-tokens', 30, $subscription);

    expect(fms()->usage('ai-tokens', $subscription))->toBe(80);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(0);
});

it('does not fire an overage event for a refund', function () {
    Event::fake([FeatureOverageRecorded::class]);

    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    fms()->increment('ai-tokens', 110, $subscription);
    fms()->decrement('ai-tokens', 5, $subscription);

    // A credit is a decision about money; inventing one from a usage correction
    // is not this package's call.
    Event::assertDispatchedTimes(FeatureOverageRecorded::class, 1);
});

it('records overage on the unenforced increment path too', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    // increment() does not ENFORCE the quota, and never has. It must still
    // RECORD the billable share: an invoice built from a column that only some
    // code paths maintain is worse than no column.
    fms()->increment('ai-tokens', 130, $subscription);

    expect(fms()->overage('ai-tokens', $subscription))->toBe(30);
});

it('refuses a negative amount rather than letting it past the ceiling', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100],
    ]);

    fms()->tryIncrement('ai-tokens', -50, $subscription);
})->throws(\InvalidArgumentException::class, 'negative');

it('resets overage with the billing period', function () {
    [, $subscription] = seedPlan([
        'ai-tokens' => ['type' => 'resource', 'included_quantity' => 100, 'overage_limit' => 50],
    ]);

    fms()->increment('ai-tokens', 130, $subscription);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(30);

    fms()->resetPeriodUsage($subscription);

    // A new period is a new row, so overage resets with it for free — which is
    // the argument for it being a column on the per-period row.
    expect(fms()->usage('ai-tokens', $subscription))->toBe(0);
    expect(fms()->overage('ai-tokens', $subscription))->toBe(0);
});

// ---- The schema itself --------------------------------------------------

it('creates feature_usages with a subscription_id matching the host key', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('feature_usages'))->toBeTrue();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('feature_usages', 'overage_quantity'))->toBeTrue();

    $shape = \ParticleAcademy\Fms\Database\FeatureUsagesSchema::currentSubscriptionShape();

    // SQLite reports every string column as a bare varchar, so the detected type
    // is 'string' rather than 'ulid' — what matters is that it is NOT an
    // integer, which is what the old foreignId() would have produced and what
    // would have failed against a char(26) primary key on MySQL.
    expect($shape['type'])->not->toBe('bigint');
});
