<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Fixtures;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** A subject `Fms` can resolve a subscription from. */
class TestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'test_subjects';

    protected $guarded = [];
}
