<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ParticleAcademy\Fms\Models\FeatureUsage;

/**
 * The host's billing subscription.
 *
 * `Fms` requires exactly three things of it, and they are documented on the
 * service: an `active()` scope, a `product()` **method** returning the product,
 * and a `featureUsages()` relationship. Note `product()` is not a relation — the
 * service calls it and uses the result as a model.
 */
class TestBillingSubscription extends Model
{
    use HasUlids;

    protected $table = Commerce::SUBSCRIPTIONS;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['renews_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function product(): ?TestProduct
    {
        return TestProduct::query()->find($this->product_id);
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class, 'subscription_id');
    }
}
