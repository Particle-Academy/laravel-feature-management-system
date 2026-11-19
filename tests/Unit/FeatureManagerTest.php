<?php

namespace ParticleAcademy\Fms\Tests\Unit;

use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use ParticleAcademy\Fms\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

uses(TestCase::class);

it('implements FeatureManagerInterface', function () {
    $registry = new FmsFeatureRegistry();
    $manager = new FeatureManager($registry);
    
    expect($manager)->toBeInstanceOf(\ParticleAcademy\Fms\Contracts\FeatureManagerInterface::class);
});

it('checks gate first when gate exists', function () {
    Gate::define('test-gate-unit', fn() => true);
    
    // A registry that records every lookup: it should not be asked at all
    // when a gate answers.
    $registry = new class extends FmsFeatureRegistry
    {
        public int $definitionCalls = 0;

        public function definition(string $key): ?array
        {
            $this->definitionCalls++;

            return parent::definition($key);
        }
    };

    $manager = new FeatureManager($registry);

    $result = $manager->canAccess('test-gate-unit', null);

    // Result should be boolean (true if gate works, false if it falls through)
    expect($result)->toBeBool();
    expect($registry->definitionCalls)->toBe(0);
});

it('falls back to registry when gate does not exist', function () {
    $registry = new FmsFeatureRegistry();
    $registry->register('test-feature', [
        'name' => 'Test',
        'enabled' => true,
    ]);
    
    $manager = new FeatureManager($registry);
    
    expect($manager->canAccess('test-feature'))->toBeTrue();
});

it('evaluates boolean config values correctly', function () {
    $registry = new FmsFeatureRegistry();
    $manager = new FeatureManager($registry);
    
    $reflection = new \ReflectionClass($manager);
    $method = $reflection->getMethod('evaluateConfigValue');
    $method->setAccessible(true);
    
    expect($method->invoke($manager, true, null, null))->toBeTrue();
    expect($method->invoke($manager, false, null, null))->toBeFalse();
    expect($method->invoke($manager, fn() => true, null, null))->toBeTrue();
    expect($method->invoke($manager, fn() => false, null, null))->toBeFalse();
});

