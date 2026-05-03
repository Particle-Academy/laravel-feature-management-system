<?php

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureGroupRegistry;
use ParticleAcademy\Fms\ValueObjects\FeatureGroup;
use ParticleAcademy\Fms\Tests\Fixtures\TestSubject;

uses(\ParticleAcademy\Fms\Tests\TestCase::class);

beforeEach(function () {
    config()->set('fms.features', [
        'use-mcp' => ['name' => 'Use MCP', 'type' => 'boolean', 'enabled' => false],
        'ai-tokens' => ['name' => 'AI Tokens', 'type' => 'resource', 'limit' => 1000],
        'sso' => ['name' => 'SSO', 'type' => 'boolean', 'enabled' => false],
    ]);
    config()->set('fms.groups', []);
    forgetFmsSingletons();

    \Illuminate\Support\Facades\Schema::dropIfExists('test_subjects');
    \Illuminate\Support\Facades\Schema::create('test_subjects', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->boolean('in_beta')->default(false);
        $table->timestamps();
    });
});

it('registers a config-defined group and exposes its features through the registry', function () {
    applyGroups([
        'pro-plan' => [
            'name' => 'Pro Plan',
            'features' => ['use-mcp', 'ai-tokens'],
        ],
    ]);

    $registry = app(FmsFeatureGroupRegistry::class);

    expect($registry->has('pro-plan'))->toBeTrue();
    expect($registry->resolvedFeatures('pro-plan'))
        ->toEqual(['use-mcp', 'ai-tokens']);
    expect($registry->groupsContaining('use-mcp'))->toEqual(['pro-plan']);
});

it('enables features for a subject assigned to a group via the polymorphic pivot', function () {
    applyGroups([
        'pro-plan' => [
            'features' => ['use-mcp'],
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    expect($manager->canAccess('use-mcp', $user))->toBeFalse();

    $user->attachFeatureGroup('pro-plan');
    $user->load('featureGroupAssignments');
    expect($manager->canAccess('use-mcp', $user))->toBeTrue();

    $user->detachFeatureGroup('pro-plan');
    $user->load('featureGroupAssignments');
    expect($manager->canAccess('use-mcp', $user))->toBeFalse();
});

it('takes the MAX limit across enabled groups for resource features', function () {
    applyGroups([
        'pro-plan' => [
            'features' => ['ai-tokens'],
            'overrides' => ['ai-tokens' => ['limit' => 5000]],
        ],
        'enterprise' => [
            'features' => ['ai-tokens'],
            'overrides' => ['ai-tokens' => ['limit' => 50000]],
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    $user->attachFeatureGroup('pro-plan');
    $user->attachFeatureGroup('enterprise');
    $user->load('featureGroupAssignments');

    // 50000 (enterprise) > 5000 (pro) > 1000 (base config) — MAX wins
    expect($manager->remaining('ai-tokens', $user))->toBe(50000);
});

it('refuses to take a smaller group limit when the base feature has a larger one', function () {
    config()->set('fms.features.ai-tokens.limit', 100000);
    applyGroups([
        'pro-plan' => [
            'features' => ['ai-tokens'],
            'overrides' => ['ai-tokens' => ['limit' => 5000]],
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    $user->attachFeatureGroup('pro-plan');
    $user->load('featureGroupAssignments');

    expect($manager->remaining('ai-tokens', $user))->toBe(100000);
});

it('resolves features from extended groups one level deep', function () {
    applyGroups([
        'pro-plan' => ['features' => ['use-mcp', 'ai-tokens']],
        'enterprise' => [
            'extends' => ['pro-plan'],
            'features' => ['sso'],
        ],
    ]);

    $registry = app(FmsFeatureGroupRegistry::class);

    expect($registry->resolvedFeatures('enterprise'))
        ->toEqualCanonicalizing(['use-mcp', 'ai-tokens', 'sso']);
});

it('throws on a self-referential extends', function () {
    applyGroups([
        'self-loop' => [
            'extends' => ['self-loop'],
            'features' => ['use-mcp'],
        ],
    ]);

    $registry = app(FmsFeatureGroupRegistry::class);

    expect(fn () => $registry->resolvedFeatures('self-loop'))
        ->toThrow(\RuntimeException::class, 'cannot extend itself');
});

it('throws on a two-group cycle', function () {
    applyGroups([
        'a' => ['extends' => ['b'], 'features' => []],
        'b' => ['extends' => ['a'], 'features' => []],
    ]);

    $registry = app(FmsFeatureGroupRegistry::class);

    expect(fn () => $registry->resolvedFeatures('a'))
        ->toThrow(\RuntimeException::class, 'cycle detected');
});

it('honors a callable enabled gate without requiring a pivot assignment', function () {
    applyGroups([
        'beta-cohort' => [
            'features' => ['use-mcp'],
            'enabled' => fn ($user) => $user?->in_beta === true,
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    expect($manager->canAccess('use-mcp', $user))->toBeFalse();

    $user->in_beta = true;
    expect($manager->canAccess('use-mcp', $user))->toBeTrue();
});

it('explain() reports the group source for a group-enabled feature', function () {
    applyGroups([
        'pro-plan' => [
            'features' => ['use-mcp'],
            'overrides' => ['use-mcp' => ['limit' => 10]],
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    $user->attachFeatureGroup('pro-plan');
    $user->load('featureGroupAssignments');

    $result = $manager->explain('use-mcp', $user);
    expect($result['source'])->toBe('group');
    expect($result['enabled'])->toBeTrue();
    expect($result['detail']['groups'])->toEqual(['pro-plan']);
});

it('enabled() includes features only exposed through groups', function () {
    applyGroups([
        'pro-plan' => [
            'features' => ['ai-tokens'],
        ],
    ]);

    $manager = app(FeatureManagerInterface::class);

    $user = createSubject();
    $user->attachFeatureGroup('pro-plan');
    $user->load('featureGroupAssignments');

    expect($manager->enabled($user))->toContain('ai-tokens');
});

/**
 * Wire fresh group + manager singletons to pick up the latest config.
 * The service-provider factories read config at instantiation, so once
 * we forget the cached instances the next resolve sees the new groups.
 */
function applyGroups(array $groups): void
{
    config()->set('fms.groups', $groups);
    forgetFmsSingletons();
}

function forgetFmsSingletons(): void
{
    app()->forgetInstance(FmsFeatureGroupRegistry::class);
    app()->forgetInstance(FeatureManagerInterface::class);
    app()->forgetInstance(FeatureManager::class);
}

function createSubject(): TestSubject
{
    return TestSubject::create(['name' => 'subject']);
}
