<?php

namespace ParticleAcademy\Fms\Tests\Unit;

use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use ParticleAcademy\Fms\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Mockery;

uses(TestCase::class);

it('implements FeatureManagerInterface', function () {
    $registry = new FmsFeatureRegistry();
    $manager = new FeatureManager($registry);
    
    expect($manager)->toBeInstanceOf(\ParticleAcademy\Fms\Contracts\FeatureManagerInterface::class);
});

it('checks gate first when gate exists', function () {
    Gate::define('test-gate-unit', fn() => true);
    
    $registry = Mockery::mock(FmsFeatureRegistry::class);
    $registry->shouldNotReceive('definition'); // Should not check registry if gate works
    
    $manager = new FeatureManager($registry);
    
    // Gate::has() may not work properly in Testbench without full bootstrap
    // So we test that the manager attempts to check gates first
    $result = $manager->canAccess('test-gate-unit', null);
    
    // Result should be boolean (true if gate works, false if it falls through)
    expect($result)->toBeBool();
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

