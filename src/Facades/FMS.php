<?php

namespace ParticleAcademy\Fms\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Feature Management System (FMS) Facade
 * Why: Provides a simple, expressive static API for feature access control
 * throughout the application.
 *
 * @method static bool canAccess(string $feature, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, mixed $context = null)
 * @method static bool isEnabled(string $feature, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, mixed $context = null)
 * @method static bool hasFeature(string $feature, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, mixed $context = null)
 * @method static int|null remaining(string $feature, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, mixed $context = null)
 * @method static array<string> enabled(?\Illuminate\Contracts\Auth\Authenticatable $user = null, mixed $context = null)
 *
 * @see \ParticleAcademy\Fms\Services\FeatureManager
 */
class FMS extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \ParticleAcademy\Fms\Contracts\FeatureManagerInterface::class;
    }
}

