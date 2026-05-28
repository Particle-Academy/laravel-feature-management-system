<?php

namespace ParticleAcademy\Fms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeatureUsage model
 * Why: Tracks metered consumption of resource-based product features per
 * billing subscription and period (e.g. seats used, tokens consumed).
 */
class FeatureUsage extends Model
{
    use HasFactory, HasUlids;

    /**
     * Resolve the table name from config so consumers with prefixed or
     * renamed schemas (e.g. `fms_feature_usages`) don't have to decorate
     * the model. Falls back to the historical default.
     */
    public function getTable(): string
    {
        // ?? (not the config default arg) so an explicit null in config
        // still falls back rather than returning null.
        return config('fms.tables.feature_usages') ?? 'feature_usages';
    }

    protected $fillable = [
        'subscription_id',
        'product_feature_id',
        'used_quantity',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'used_quantity' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /**
     * Subscription this usage belongs to.
     * NOTE: Update the BillingSubscription class reference to match your application
     */
    public function subscription(): BelongsTo
    {
        // Replace 'App\\Models\\BillingSubscription' with your subscription model class
        return $this->belongsTo('App\\Models\\BillingSubscription', 'subscription_id');
    }

    /**
     * Product feature this usage tracks.
     * NOTE: Uses the configured ProductFeature model class from FMS config.
     */
    public function productFeature(): BelongsTo
    {
        $productFeatureClass = config('fms.product_feature_model');
        
        if (! $productFeatureClass || ! class_exists($productFeatureClass)) {
            throw new \RuntimeException('FMS product_feature_model is not configured. Please set config("fms.product_feature_model") to your ProductFeature model class.');
        }
        
        return $this->belongsTo($productFeatureClass, 'product_feature_id');
    }
}

