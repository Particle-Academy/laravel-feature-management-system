<?php

namespace ParticleAcademy\Fms;

use ParticleAcademy\Fms\Commands\SyncFmsFeatures;
use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use Illuminate\Support\ServiceProvider;

class FmsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/fms.php',
            'fms'
        );

        // Register feature registry as singleton
        $this->app->singleton(FmsFeatureRegistry::class, function ($app) {
            $registry = new FmsFeatureRegistry();
            
            // Load features from config
            $features = config('fms.features', []);
            foreach ($features as $key => $definition) {
                $registry->register($key, $definition);
            }
            
            return $registry;
        });

        // Register FeatureManager as singleton and bind to interface
        $this->app->singleton(FeatureManagerInterface::class, function ($app) {
            return new FeatureManager(
                $app->make(FmsFeatureRegistry::class)
            );
        });

        // Also register as FeatureManager for convenience
        $this->app->alias(FeatureManagerInterface::class, FeatureManager::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load helper functions
        if (file_exists($helperPath = __DIR__.'/helpers.php')) {
            require_once $helperPath;
        }

        // Publish migrations (optional - for customization)
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'fms-migrations');

        // Publish factories
        $this->publishes([
            __DIR__.'/../database/factories' => database_path('factories'),
        ], 'fms-factories');

        // Publish config
        $this->publishes([
            __DIR__.'/../config/fms.php' => config_path('fms.php'),
        ], 'fms-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncFmsFeatures::class,
            ]);
        }
    }
}

