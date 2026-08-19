<?php

declare(strict_types=1);

/**
 * The config file must not advertise a resolution step the package does not have.
 *
 * `config/fms.php` documented "5. Database lookups (if FeatureUsage model is
 * available)" and offered `'default_strategy' => 'database'`. Neither existed:
 * `checkDatabaseFeature()`, `getDatabaseResourceRemaining()` and
 * `getDatabaseResourceUsage()` return `false`, `null` and `0`, and
 * `default_strategy` was read by no code in the package at all. Someone
 * configuring it got silence.
 *
 * The three methods are kept as `protected` subclass extension hooks — a
 * subclass overriding one gets a working last-resort source, so removing the
 * call sites would break the people who read the source instead of the docs.
 * What was deleted is the CLAIM. This test is what stops it coming back.
 */
it('does not offer a default_strategy that nothing reads', function () {
    $config = require __DIR__.'/../../config/fms.php';

    expect($config)->not->toHaveKey('default_strategy');
});

it('does not describe a database resolution strategy', function () {
    $source = file_get_contents(__DIR__.'/../../config/fms.php');

    expect(str_contains($source, 'Database lookups'))
        ->toBeFalse('config/fms.php advertises a database resolution step the package does not implement');
});

it('keeps the subclass hooks that DO work', function () {
    $manager = new ReflectionClass(\ParticleAcademy\Fms\Services\FeatureManager::class);

    foreach (['checkDatabaseFeature', 'getDatabaseResourceRemaining', 'getDatabaseResourceUsage'] as $hook) {
        expect($manager->hasMethod($hook))->toBeTrue(
            "FeatureManager::{$hook}() is a documented extension point; a subclass may override it"
        );
        expect($manager->getMethod($hook)->isProtected())->toBeTrue();
    }
});
