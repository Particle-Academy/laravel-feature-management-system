<?php

namespace ParticleAcademy\Fms\Database\Factories;

use ParticleAcademy\Fms\Models\FeatureUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\ParticleAcademy\Fms\Models\FeatureUsage>
 */
class FeatureUsageFactory extends Factory
{
    protected $model = FeatureUsage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => null,
            'product_feature_id' => null,
            'used_quantity' => $this->faker->numberBetween(0, 1000),
            'period_start' => null,
            'period_end' => null,
        ];
    }
}

