<?php

namespace ParticleAcademy\Fms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pivot row binding a feature group to an assignable subject.
 *
 * Why a model (not a pivot table on a relation): the polymorphic owner
 * means we don't have a single parent model to attach a many-to-many
 * relation to. Treating each row as its own model makes assignments
 * queryable from either direction (group_key → subjects, subject →
 * group_keys) without bespoke query builders.
 */
class FeatureGroupAssignment extends Model
{
    protected $table = 'feature_group_assignments';

    protected $fillable = [
        'group_key',
        'assignable_type',
        'assignable_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
