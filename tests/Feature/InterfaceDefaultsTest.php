<?php

/**
 * Adding a method to a published interface breaks every implementer, and it
 * breaks them at AUTOLOAD — before any of their code runs.
 *
 * 0.11.0 added `isEntitled()` and `canConsume()` to `FeatureManagerInterface`.
 * The changelog documented both, carefully, FOR CALLERS. Implementers are a
 * different audience with a different failure mode: they do not get to choose
 * whether to adopt the methods, and they find out like this —
 *
 *   Class App\Services\Fms\MoicFmsManager contains 2 abstract methods and must
 *   therefore be declared abstract or implement the remaining methods
 *   Script @php artisan package:discover returned with error code 255
 *
 * — with the app, and artisan itself, unbootable until fixed. Reported by a
 * consumer whose composer update fatalled mid-upgrade.
 *
 * The bite is sharper than "someone implemented our interface": they DECORATE
 * the manager, which is the pattern this package encourages precisely so people
 * do not fork it. The decorator is what an interface addition breaks first.
 *
 * `ProvidesFeatureManagerDefaults` is the mitigation. Both defaults are built
 * only from methods that were ALREADY on the interface, so a decorator that
 * `use`s the trait keeps compiling and behaves the way the concrete service
 * does.
 */

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Concerns\ProvidesFeatureManagerDefaults;

/** A minimal implementer: only the pre-0.11.0 methods, plus the trait. */
final class TraitOnlyManager implements FeatureManagerInterface
{
    use ProvidesFeatureManagerDefaults;

    public function __construct(
        private bool $access = true,
        private ?int $left = 5,
    ) {}

    public function canAccess(string $feature, mixed $subject = null, mixed $context = null): bool
    {
        return $this->access;
    }

    public function isEnabled(string $feature, mixed $subject = null, mixed $context = null): bool
    {
        return $this->access;
    }

    public function hasFeature(string $feature, mixed $subject = null, mixed $context = null): bool
    {
        return $this->access;
    }

    public function remaining(string $feature, mixed $subject = null, mixed $context = null): ?int
    {
        return $this->left;
    }

    public function enabled(mixed $subject = null, mixed $context = null): array
    {
        return [];
    }
}

it('lets a pre-0.11.0 implementer satisfy the interface by adding one trait', function () {
    // The regression itself: without the trait this class is abstract and PHP
    // refuses to load it. Instantiating is the assertion.
    $m = new TraitOnlyManager();

    expect($m)->toBeInstanceOf(FeatureManagerInterface::class);
});

it('defaults isEntitled to canAccess, which is what the concrete service does', function () {
    expect((new TraitOnlyManager(access: true))->isEntitled('reports'))->toBeTrue()
        ->and((new TraitOnlyManager(access: false))->isEntitled('reports'))->toBeFalse();
});

it('denies consumption when not entitled, whatever the quota says', function () {
    expect((new TraitOnlyManager(access: false, left: 100))->canConsume('reports'))->toBeFalse();
});

it('allows consumption only while the amount fits the remaining quota', function () {
    $m = new TraitOnlyManager(access: true, left: 5);

    expect($m->canConsume('reports', amount: 5))->toBeTrue()
        ->and($m->canConsume('reports', amount: 6))->toBeFalse();
});

it('treats a null remaining as UNLIMITED, never as zero', function () {
    // The trap this package has hit before, in the direction that turns the most
    // generous configuration into the most restrictive outcome.
    $m = new TraitOnlyManager(access: true, left: null);

    expect($m->canConsume('reports', amount: 1_000_000))->toBeTrue();
});
