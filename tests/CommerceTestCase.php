<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ParticleAcademy\Fms\Tests\Fixtures\Commerce;
use ParticleAcademy\Fms\Tests\Fixtures\TestBillingSubscription;
use ParticleAcademy\Fms\Tests\Fixtures\TestProductFeature;
use ParticleAcademy\Fms\Tests\Fixtures\TestUser;

/**
 * A booted app with the commerce tables `Fms` needs, wired to fixture models.
 *
 * The host's tables are created BEFORE the package migrations run, because the
 * create migration self-skips when a foreign-key target is absent — the guard
 * that lets it sit early in a consumer's chronological order. Building them in
 * the wrong order here would silently produce a suite that tests nothing,
 * because `feature_usages` would never be created and every metering assertion
 * would fail for the wrong reason.
 *
 * Subscriptions are **ULID-keyed**, and `subscription_key_type` is set
 * explicitly to `ulid`. That is the pairing `laravel-catalog` implies and the
 * one FMS's bigint `subscription_id` used to make impossible.
 */
abstract class CommerceTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('fms.product_feature_model', TestProductFeature::class);
        $app['config']->set('fms.subscription_model', TestBillingSubscription::class);
        $app['config']->set('fms.user_model', TestUser::class);
        $app['config']->set('fms.tables.subscriptions', Commerce::SUBSCRIPTIONS);
        $app['config']->set('fms.tables.product_features', Commerce::FEATURES);
        $app['config']->set('fms.subscription_key_type', 'ulid');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('test_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('in_beta')->default(false);
            $table->timestamps();
        });

        Commerce::migrate();

        parent::defineDatabaseMigrations();
    }
}
