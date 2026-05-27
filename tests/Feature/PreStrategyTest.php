<?php

namespace ParticleAcademy\Fms\Tests\Feature;

use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use ParticleAcademy\Fms\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

uses(TestCase::class);

/*
 * Pre-strategies (v0.6.0, issue #1) sit above Gate / Registry / Groups
 * / Config / Database in the resolution chain. Each strategy receives
 * (feature, user, context) and returns ?bool — first non-null wins.
 *
 * Mirror coverage exists for registerPreRemainingStrategy() on the
 * remaining() path.
 */

function makeManager(): FeatureManager
{
    $registry = new FmsFeatureRegistry();

    return new FeatureManager($registry);
}

it('runs pre-strategies before Gate and respects their verdict', function () {
    Gate::define('billing.export', fn () => true);

    $manager = makeManager();
    $manager->registerPreStrategy('subscription', function ($feature, $user, $context): ?bool {
        // Authoritative no: subscription says blocked even though Gate allows.
        return $feature === 'billing.export' ? false : null;
    });

    expect($manager->canAccess('billing.export'))->toBeFalse();
});

it('falls through when a pre-strategy returns null', function () {
    $registry = new FmsFeatureRegistry();
    $registry->register('analytics.dashboard', ['enabled' => true]);
    $manager = new FeatureManager($registry);

    $callCount = 0;
    $manager->registerPreStrategy('subscription', function () use (&$callCount): ?bool {
        $callCount++;

        return null;
    });

    expect($manager->canAccess('analytics.dashboard'))->toBeTrue();
    expect($callCount)->toBe(1);
});

it('uses the first non-null verdict and skips later strategies', function () {
    $manager = makeManager();

    $calls = [];
    $manager->registerPreStrategy('first', function ($feature) use (&$calls): ?bool {
        $calls[] = 'first';

        return null;
    });
    $manager->registerPreStrategy('second', function ($feature) use (&$calls): ?bool {
        $calls[] = 'second';

        return true;
    });
    $manager->registerPreStrategy('third', function ($feature) use (&$calls): ?bool {
        $calls[] = 'third';

        return false;
    });

    expect($manager->canAccess('anything'))->toBeTrue();
    expect($calls)->toBe(['first', 'second']); // third not consulted
});

it('passes feature, user, and context to the closure', function () {
    $manager = makeManager();
    $captured = [];

    $manager->registerPreStrategy('capture', function ($feature, $user, $context) use (&$captured): ?bool {
        $captured = compact('feature', 'user', 'context');

        return true;
    });

    $manager->canAccess('seats.invite', 'user-123', ['team_id' => 7]);

    expect($captured['feature'])->toBe('seats.invite');
    expect($captured['user'])->toBe('user-123');
    expect($captured['context'])->toBe(['team_id' => 7]);
});

it('re-registering a name replaces the previous strategy', function () {
    $manager = makeManager();

    $manager->registerPreStrategy('flag', fn () => true);
    $manager->registerPreStrategy('flag', fn () => false);

    expect($manager->canAccess('whatever'))->toBeFalse();
    expect($manager->preStrategyNames())->toBe(['flag']);
});

it('unregisterPreStrategy removes the strategy', function () {
    $manager = makeManager();
    $manager->registerPreStrategy('flag', fn () => false);

    expect($manager->canAccess('whatever'))->toBeFalse();

    $manager->unregisterPreStrategy('flag');

    expect($manager->preStrategyNames())->toBe([]);
    expect($manager->canAccess('whatever'))->toBeFalse(); // default no-source verdict
});

it('unregisterPreStrategy is a no-op for unknown names', function () {
    $manager = makeManager();

    $manager->unregisterPreStrategy('nonexistent'); // should not throw

    expect($manager->preStrategyNames())->toBe([]);
});

it('explain() reports pre-strategy source and name when one answers', function () {
    Gate::define('billing.export-explain', fn () => true);

    $manager = makeManager();
    $manager->registerPreStrategy('subscription', fn ($f) => $f === 'billing.export-explain' ? false : null);

    $result = $manager->explain('billing.export-explain');

    expect($result['source'])->toBe('pre-strategy');
    expect($result['enabled'])->toBeFalse();
    expect($result['detail'])->toBe(['name' => 'subscription']);
});

it('explain() falls through to existing chain when all pre-strategies return null', function () {
    $registry = new FmsFeatureRegistry();
    $registry->register('analytics.dashboard', ['enabled' => true]);
    $manager = new FeatureManager($registry);

    $manager->registerPreStrategy('noop', fn () => null);

    $result = $manager->explain('analytics.dashboard');

    expect($result['source'])->toBe('registry');
    expect($result['enabled'])->toBeTrue();
});

it('remaining() consults pre-remaining strategies before registry', function () {
    $registry = new FmsFeatureRegistry();
    $registry->register('seats.invite', [
        'type' => 'resource',
        'limit' => 5,
    ]);
    $manager = new FeatureManager($registry);

    $manager->registerPreRemainingStrategy('subscription-quota', function ($feature): ?int {
        // Subscription overrides the registry's 5 — say there are 42 left.
        return $feature === 'seats.invite' ? 42 : null;
    });

    expect($manager->remaining('seats.invite'))->toBe(42);
});

it('remaining() clamps a negative pre-strategy verdict to 0', function () {
    $manager = makeManager();
    $manager->registerPreRemainingStrategy('quota', fn () => -7);

    expect($manager->remaining('anything'))->toBe(0);
});

it('remaining() falls through when pre-remaining strategies return null', function () {
    $registry = new FmsFeatureRegistry();
    $registry->register('seats.invite', [
        'type' => 'resource',
        'limit' => 5,
        'usage' => fn () => 2,
    ]);
    $manager = new FeatureManager($registry);

    $manager->registerPreRemainingStrategy('quota', fn () => null);

    expect($manager->remaining('seats.invite'))->toBe(3);
});

it('preRemainingStrategyNames lists strategies in registration order', function () {
    $manager = makeManager();
    $manager->registerPreRemainingStrategy('a', fn () => null);
    $manager->registerPreRemainingStrategy('b', fn () => null);

    expect($manager->preRemainingStrategyNames())->toBe(['a', 'b']);

    $manager->unregisterPreRemainingStrategy('a');

    expect($manager->preRemainingStrategyNames())->toBe(['b']);
});
