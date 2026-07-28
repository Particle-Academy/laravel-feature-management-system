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

/**
 * The cache-safe callable form works, and gets the same signature.
 *
 * `config/fms.php` cannot hold a closure: Laravel serialises cached config with
 * `var_export`, which emits `\Closure::__set_state(...)` — a method Closure does
 * not have — so one closure anywhere in the file fails `php artisan config:cache`,
 * and that is part of `optimize` and standard in production deploys.
 *
 * The documented escape is a `[Class::class, 'method']` callable: an array of
 * strings, so it survives `var_export`, and still callable. Nothing tested it,
 * which matters now that the docs steer people to it — including the arity
 * detection added in 0.8.0, which has a separate ReflectionMethod branch for
 * array callables that no test exercised.
 */
class CacheSafeUsage
{
    public static function forUser(mixed $user, mixed $context = null): int
    {
        return 30;
    }

    /** The pre-0.8.0 order, to prove the deprecation path covers arrays too. */
    public static function legacy(string $feature, mixed $user, mixed $context = null): int
    {
        return $feature === 'tokens' ? 30 : 0;
    }
}

it('accepts a [Class::class, method] callable, the form config:cache allows', function () {
    config(['fms.features.tokens' => [
        'type' => 'resource',
        'limit' => 100,
        'usage' => [CacheSafeUsage::class, 'forUser'],
    ]]);

    expect(app(FeatureManagerInterface::class)->remaining('tokens', 'the-user'))->toBe(70);
});

it('survives var_export, which is what config:cache actually does', function () {
    // The mechanism, not a proxy for it: a closure emits a __set_state call
    // that fatals on load; an array callable round-trips.
    $exported = var_export([CacheSafeUsage::class, 'forUser'], true);

    expect($exported)->not->toContain('__set_state');
    expect(eval("return {$exported};"))->toBe([CacheSafeUsage::class, 'forUser']);
});

it('detects arity on an array callable, not just a closure', function () {
    // 0.8.0's shim reflects arrays through ReflectionMethod. If that branch
    // were wrong, a three-parameter METHOD would silently get the new order and
    // meter the wrong thing — the exact bug, wearing a different hat.
    $deprecation = null;
    set_error_handler(function (int $level, string $message) use (&$deprecation) {
        $deprecation = $message;

        return true;
    }, E_USER_DEPRECATED);

    config(['fms.features.tokens' => [
        'type' => 'resource',
        'limit' => 100,
        'usage' => [CacheSafeUsage::class, 'legacy'],
    ]]);

    $remaining = app(FeatureManagerInterface::class)->remaining('tokens', 'the-user');

    restore_error_handler();

    expect($remaining)->toBe(70);
    expect($deprecation)->toContain('($user, $context)');
});
