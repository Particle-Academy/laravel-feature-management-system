<?php

namespace ParticleAcademy\Fms\Tests\Feature;

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Tests\TestCase;

uses(TestCase::class);

/**
 * What a feature-definition callback actually receives.
 *
 * Nothing asserted this before, which is how the bug survived: every existing
 * test declares its callback as `fn() => 30` — zero parameters — and an
 * argument-less closure passes under any signature at all.
 *
 * `usage` and `remaining` were invoked as `($feature, $user, $context)`, key
 * FIRST, while `check`, `enabled` and `limit` all took `($user, $context)` and
 * the README documented `($user)` for every one of them. So anyone following the
 * docs bound `$user` to the feature-key string and measured the wrong thing —
 * reported by GuardCard.net, whose metered allowance never ran out.
 */
function manager(): FeatureManagerInterface
{
    return app(FeatureManagerInterface::class);
}

it('hands a usage callback the user first, as the docs have always said', function () {
    $seen = null;

    config(['fms.features.tokens' => [
        'type' => 'resource',
        'limit' => 100,
        'usage' => function ($user, $context) use (&$seen) {
            $seen = ['user' => $user, 'context' => $context];

            return 30;
        },
    ]]);

    expect(manager()->remaining('tokens', 'the-user'))->toBe(70);
    expect($seen['user'])->toBe('the-user');
});

it('hands a remaining callback the same shape', function () {
    // `remaining` was the second outlier, and a fix that moved only `usage`
    // would leave the pair disagreeing with each other.
    $seen = null;

    config(['fms.features.tokens' => [
        'type' => 'resource',
        'remaining' => function ($user, $context) use (&$seen) {
            $seen = $user;

            return 5;
        },
    ]]);

    expect(manager()->remaining('tokens', 'the-user'))->toBe(5);
    expect($seen)->toBe('the-user');
});

it('agrees with how check and limit callbacks are already invoked', function () {
    // The argument for `($user, $context)` is that it is what the rest of the
    // class does. If that stopped being true the fix would have picked the
    // wrong convention, so it is asserted rather than assumed.
    $checkSaw = null;
    $limitSaw = null;

    config(['fms.features.tokens' => [
        'type' => 'resource',
        'check' => function ($user, $context) use (&$checkSaw) {
            $checkSaw = $user;

            return true;
        },
        'limit' => function ($user, $context) use (&$limitSaw) {
            $limitSaw = $user;

            return 10;
        },
        'usage' => fn ($user, $context) => 4,
    ]]);

    expect(manager()->remaining('tokens', 'the-user'))->toBe(6);
    expect($limitSaw)->toBe('the-user');

    manager()->canAccess('tokens', 'the-user');
    expect($checkSaw)->toBe('the-user');
});

it('still honours a three-parameter callback, and says so out loud', function () {
    // Someone who read the source rather than the docs wrote the old order.
    // Passing them two arguments would not fail — it would shift every argument
    // by one and keep returning numbers, which is the silent wrong answer this
    // whole fix exists to end. So the old shape keeps working and deprecates.
    $seen = null;
    $deprecation = null;

    set_error_handler(function (int $level, string $message) use (&$deprecation) {
        $deprecation = $message;

        return true;
    }, E_USER_DEPRECATED);

    config(['fms.features.tokens' => [
        'type' => 'resource',
        'limit' => 100,
        'usage' => function ($feature, $user, $context) use (&$seen) {
            $seen = ['feature' => $feature, 'user' => $user];

            return 30;
        },
    ]]);

    $remaining = manager()->remaining('tokens', 'the-user');

    restore_error_handler();

    expect($remaining)->toBe(70);
    expect($seen)->toBe(['feature' => 'tokens', 'user' => 'the-user']);
    expect($deprecation)->toContain('($user, $context)');
});

it('does not deprecate a callback that takes fewer arguments than it is given', function () {
    // `fn() => 30` and `fn($user) => …` are both fine and extremely common —
    // PHP discards extra arguments to a closure. Warning about them would make
    // the deprecation noise that every existing test trips over, and noise is
    // how a real deprecation gets ignored.
    $deprecation = null;

    set_error_handler(function (int $level, string $message) use (&$deprecation) {
        $deprecation = $message;

        return true;
    }, E_USER_DEPRECATED);

    config(['fms.features.tokens' => ['type' => 'resource', 'limit' => 100, 'usage' => fn () => 30]]);
    manager()->remaining('tokens', 'the-user');

    restore_error_handler();

    expect($deprecation)->toBeNull();
});
