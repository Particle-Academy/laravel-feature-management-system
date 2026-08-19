<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;
use ParticleAcademy\Fms\Quota;

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
 *
 * Loaded from the INSTALLED package via `Conformance::cases()`, never a relative
 * path to a sibling checkout: the conformance repo's own runner notes record why
 * — its two older parity harnesses hard-coded `../../<repo>/src/`, so they
 * worked in exactly one directory layout and silently no-op'd everywhere else,
 * CI included.
 */

/** Moved deliberately, never automatically. A pin that follows disk asserts nothing. */
const PINNED_SUITE_VERSION = '0.4.0';

/** Dispatch one case to the implementation under test. */
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

it('loads the shared/feature-entitlement suite from the installed package', function () {
    // The vacuity guard, and the one that matters most. A suite that resolves,
    // returns nothing and reports "0 failed" reads exactly like full coverage.
    expect(count(Conformance::cases('shared/feature-entitlement')))->toBeGreaterThanOrEqual(26);
    expect(Conformance::version())->toBe(PINNED_SUITE_VERSION);
});

it('agrees with the shared feature-entitlement table', function () {
    $summary = Conformance::runTable('shared/feature-entitlement', 'runEntitlementCase'(...));

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
