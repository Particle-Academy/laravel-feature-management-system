<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;
use ParticleAcademy\Fms\Quota;
use ParticleAcademy\Fms\Tests\Support\SharedSuites;

/**
 * The `shared/feature-entitlement` table, run against THIS side.
 *
 * `@particle-academy/fancy-features` and `fancy-features-py` run the identical
 * rows from the identical file. That is the whole mechanism: three runtimes read
 * one table, so a divergence is a red build in whichever one drifted rather than
 * a support ticket months later.
 *
 * ## The two rows that carry the weight
 *
 * **0002** — an enabled resource grant with zero quota left is still ENTITLED.
 * Until 0.11.0 the answer depended on where the feature was defined: a registry
 * feature was on when its `enabled` said so, a catalog-backed one only while
 * quota remained. An implementation that puts the quota check back into
 * entitlement fails this row and 0004, and nothing else.
 *
 * **0018** — consumption that starts *above* the included line bills the whole
 * amount, not the distance from the line. The obvious
 * `max(0, after - included)` answers 50 where the truth is 10, re-billing every
 * unit already recorded. That one is an invoice, not a test failure.
 */
function runEntitlementCase(array $case): mixed
{
    $in = $case['input'];

    return match ($case['fn']) {
        'entitled' => Quota::entitled(
            $in['enabled'],
            $in['type'],
            $in['includedQuantity'],
            $in['used'],
        ),
        'consumptionCeiling' => Quota::consumptionCeiling(
            $in['includedQuantity'],
            $in['overageLimit'],
        ),
        'allowsConsumption' => Quota::allowsConsumption(
            $in['used'],
            $in['amount'],
            $in['ceiling'],
        ),
        'overageDelta' => Quota::overageDelta(
            $in['used'],
            $in['amount'],
            $in['includedQuantity'],
        ),
        'canConsume' => Quota::canConsume(
            $in['enabled'],
            $in['includedQuantity'],
            $in['overageLimit'],
            $in['used'],
            $in['amount'],
        ),
        default => throw new RuntimeException(
            "case {$case['id']} calls unimplemented fn {$case['fn']}"
        ),
    };
}

it('loads the shared/feature-entitlement suite', function () {
    // The vacuity guard, and the one that matters most. A suite that resolves,
    // returns nothing and reports "0 failed" reads exactly like full coverage.
    $cases = Conformance::cases(
        'shared/feature-entitlement',
        SharedSuites::root('shared/feature-entitlement'),
    );

    expect(count($cases))->toBeGreaterThanOrEqual(26);
});

it('agrees with the shared feature-entitlement table', function () {
    $summary = SharedSuites::runTable('shared/feature-entitlement', 'runEntitlementCase'(...));

    // Printed unconditionally, pass or fail. A summary shown only on failure
    // cannot tell anyone the suite ran at all.
    fwrite(STDERR, "\n".Conformance::formatSummary($summary)."\n");

    expect($summary['ok'])->toBeTrue(Conformance::formatSummary($summary));
});

it('refuses a negative consumption rather than letting it past the ceiling', function () {
    // The table cannot express a raise, so each runtime asserts its own. A
    // negative "consume" is a refund wearing a disguise and would walk straight
    // through `used + amount <= ceiling`.
    expect(Quota::allowsConsumption(100, -50, 100))->toBeTrue();

    // ...which is why the SERVICE refuses it, not the arithmetic. See
    // FmsSubscriptionScopeTest — tryIncrement() rejects a negative amount.
})->group('boundary');
