<?php

namespace ParticleAcademy\Fms\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use ParticleAcademy\Fms\FmsServiceProvider;

/**
 * A hand-built Laravel application for the suite: the framework this package
 * integrates with, booted directly, with no orchestra/testbench.
 *
 * Every test gets a fresh application on its own in-memory SQLite database, so
 * nothing persists between tests and nothing can reach a real database.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Boot an empty application skeleton in a temp directory, with the
     * framework's own default configuration and this package's provider.
     */
    public function createApplication(): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-fms-tests';
        if (! is_dir($base.'/bootstrap/cache')) {
            mkdir($base.'/bootstrap/cache', 0777, true);
        }
        // The framework reads the skeleton's .env; an empty one means "no overrides".
        if (! is_file($base.'/.env')) {
            touch($base.'/.env');
        }

        $app = Application::configure(basePath: $base)
            ->withProviders($this->getPackageProviders(), withBootstrapProviders: false)
            ->withMiddleware()
            ->withExceptions()
            ->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $app['config']->set('cache.default', 'array');
            $app['config']->set('mail.default', 'array');
            $app['config']->set('queue.default', 'sync');
            // No provider keys, ever, in a test run.
            $app['config']->set('services.anthropic.key', '');
            $app['config']->set('services.openai.key', '');

            $this->defineEnvironment($app);
        });

        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineDatabaseMigrations();
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders(): array
    {
        return [
            FmsServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup FMS config
        $app['config']->set('fms.features', []);
        $app['config']->set('fms.product_feature_model', null);

        // Ensure Gate is available
        $app['config']->set('auth.defaults.guard', 'web');
    }

    /**
     * Define database migrations: the package's own, which its service
     * provider registers with the migrator.
     */
    protected function defineDatabaseMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }
}
