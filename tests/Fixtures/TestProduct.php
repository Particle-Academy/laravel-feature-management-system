<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A product with billable features. `productFeatures()` carries the pivot the
 * whole ruling turns on — `enabled`, `included_quantity` and `overage_limit`.
 */
class TestProduct extends Model
{
    use HasUlids;

    protected $table = Commerce::PRODUCTS;

    protected $guarded = [];

    public function productFeatures(): BelongsToMany
    {
        return $this->belongsToMany(TestProductFeature::class, Commerce::CONFIGS, 'product_id', 'product_feature_id')
            ->withPivot(['enabled', 'included_quantity', 'overage_limit', 'config'])
            ->withTimestamps();
    }
}
