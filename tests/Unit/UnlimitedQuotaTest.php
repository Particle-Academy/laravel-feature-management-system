<?php

declare(strict_types=1);

use ParticleAcademy\Fms\Services\Fms;

/**
 * `included_quantity = null` is documented as UNLIMITED.
 *
 * `can()` asked `remaining()` and denied on null, so the most generous
 * configuration produced the most restrictive outcome — an unlimited allowance
 * that refused every request. The Node twin has always read null as unlimited,
 * so the same row meant opposite things depending on which runtime asked.
 */
it('treats an unlimited allowance as permitting consumption', function () {
    expect(Fms::allowsConsumption(null))->toBeTrue();
});

it('still refuses an exhausted allowance', function () {
    expect(Fms::allowsConsumption(0))->toBeFalse();
    expect(Fms::allowsConsumption(-1))->toBeFalse();
});

it('permits a remaining allowance', function () {
    expect(Fms::allowsConsumption(1))->toBeTrue();
});
