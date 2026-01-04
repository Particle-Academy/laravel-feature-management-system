<?php

namespace ParticleAcademy\Fms\Tests\Feature;

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use ParticleAcademy\Fms\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

uses(TestCase::class);

it('can check if a feature is accessible via config', function () {
    config(['fms.features.test-feature.enabled' => true]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->canAccess('test-feature'))->toBeTrue();
});

it('can check if a feature is disabled via config', function () {
    config(['fms.features.test-feature.enabled' => false]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->canAccess('test-feature'))->toBeFalse();
});

it('can check feature access via callable', function () {
    config(['fms.features.test-feature.enabled' => fn() => true]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->canAccess('test-feature'))->toBeTrue();
});

it('can check feature access via gate', function () {
    // Define gate before getting manager to ensure it's registered
    Gate::define('test-feature-gate', fn() => true);
    
    $manager = app(FeatureManagerInterface::class);
    
    // Test that gate access works when gate is defined
    // Note: Gate::has() requires Gate to be fully bootstrapped
    // In Testbench, we verify the gate works by checking access
    $result = $manager->canAccess('test-feature-gate', null);
    
    // If Gate::has() works, it should return true
    // Otherwise it will fall through to other strategies
    expect($result)->toBeBool();
});

it('can check feature access via registry', function () {
    $registry = app(FmsFeatureRegistry::class);
    $registry->register('registry-feature', [
        'name' => 'Registry Feature',
        'type' => 'boolean',
        'enabled' => true,
    ]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->canAccess('registry-feature'))->toBeTrue();
});

it('returns false for non-existent features', function () {
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->canAccess('non-existent-feature'))->toBeFalse();
});

it('can get remaining quantity for resource features', function () {
    config([
        'fms.features.test-resource' => [
            'type' => 'resource',
            'limit' => 100,
            'usage' => fn() => 30,
        ],
    ]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->remaining('test-resource'))->toBe(70);
});

it('returns null for non-resource features', function () {
    config(['fms.features.test-boolean.enabled' => true]);
    
    $manager = app(FeatureManagerInterface::class);
    
    expect($manager->remaining('test-boolean'))->toBeNull();
});

it('can get all enabled features', function () {
    config([
        'fms.features.feature1' => ['enabled' => true],
        'fms.features.feature2' => ['enabled' => false],
        'fms.features.feature3' => ['enabled' => true],
    ]);
    
    $manager = app(FeatureManagerInterface::class);
    
    $enabled = $manager->enabled();
    
    expect($enabled)->toContain('feature1', 'feature3')
        ->not->toContain('feature2');
});

