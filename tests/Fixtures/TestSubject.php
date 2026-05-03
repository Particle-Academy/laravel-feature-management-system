<?php

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use ParticleAcademy\Fms\Concerns\HasFeatureGroups;

/**
 * Lightweight Eloquent subject for FMS feature-group tests.
 *
 * Implements Authenticatable so the FeatureManager treats it like a user.
 */
class TestSubject extends Model implements Authenticatable
{
    use AuthenticatableTrait;
    use HasFeatureGroups;

    protected $table = 'test_subjects';

    protected $guarded = [];

    protected $casts = [
        'in_beta' => 'boolean',
    ];
}
