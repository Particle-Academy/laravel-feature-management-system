<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The catalog of billable features. `key` is what application code names. */
class TestProductFeature extends Model
{
    use HasUlids;

    protected $table = Commerce::FEATURES;

    protected $guarded = [];
}
