<?php

namespace ParticleAcademy\Fms\Tests\Feature;

use ParticleAcademy\Fms\Http\Middleware\RequireFeature;
use ParticleAcademy\Fms\Tests\TestCase;
use Illuminate\Http\Request;

uses(TestCase::class);

it('allows access when feature is enabled', function () {
    config(['fms.features.test-feature.enabled' => true]);
    
    $middleware = app(RequireFeature::class);
    $request = Request::create('/test', 'GET');
    
    $response = $middleware->handle($request, fn($req) => response('OK'), 'test-feature');
    
    expect($response->getContent())->toBe('OK');
    expect($response->getStatusCode())->toBe(200);
});

it('denies access when feature is disabled', function () {
    config(['fms.features.test-feature.enabled' => false]);
    
    $middleware = app(RequireFeature::class);
    $request = Request::create('/test', 'GET');
    
    try {
        $response = $middleware->handle($request, fn($req) => response('OK'), 'test-feature');
        expect($response->getStatusCode())->toBe(403);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

it('returns json response for json requests', function () {
    config(['fms.features.test-feature.enabled' => false]);
    
    $middleware = app(RequireFeature::class);
    $request = Request::create('/test', 'GET');
    $request->headers->set('Accept', 'application/json');
    
    $response = $middleware->handle($request, fn($req) => response('OK'), 'test-feature');
    
    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json)->toHaveKey('message');
    expect($json)->toHaveKey('features');
});

