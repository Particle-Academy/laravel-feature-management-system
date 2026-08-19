<?php

declare(strict_types=1);

use ParticleAcademy\Fms\Database\FeatureUsagesSchema;

/**
 * Mapping a driver's reported column type onto a key type.
 *
 * Pure, so it is tested here rather than through a migration: the branch that
 * matters most is the one SQLite exercises, where every string column comes back
 * as a bare `varchar` with no length and detection can only tell an integer from
 * a string.
 */
it('reads every integer flavour as a bigint key', function (string $typeName) {
    expect(FeatureUsagesSchema::classify($typeName)['type'])->toBe('bigint');
})->with(['bigint', 'int8', 'integer', 'int', 'int4', 'smallint', 'mediumint', 'bigserial']);

it('reads a native uuid column as a uuid key', function () {
    expect(FeatureUsagesSchema::classify('uuid')['type'])->toBe('uuid');
});

it('reads char(26) as a ULID — the type laravel-catalog uses', function () {
    expect(FeatureUsagesSchema::classify('char', 'char(26)'))
        ->toBe(['type' => 'ulid', 'length' => 26]);
});

it('reads char(36) and varchar(36) as a uuid', function (string $type) {
    expect(FeatureUsagesSchema::classify(explode('(', $type)[0], $type)['type'])->toBe('uuid');
})->with(['char(36)', 'varchar(36)']);

it('carries the detected length on any other string, so a MySQL foreign key still matches', function () {
    expect(FeatureUsagesSchema::classify('varchar', 'varchar(64)'))
        ->toBe(['type' => 'string', 'length' => 64]);
});

it('reports a lengthless string as a string with no length — SQLite says nothing more', function () {
    // Every one of ulid, uuid and string(64) comes back as bare `varchar` on
    // SQLite. That is harmless there (it does not enforce foreign-key type
    // compatibility) and it is why `fms.subscription_key_type` is documented as
    // the route rather than as a fallback.
    expect(FeatureUsagesSchema::classify('varchar'))
        ->toBe(['type' => 'string', 'length' => null]);
});
