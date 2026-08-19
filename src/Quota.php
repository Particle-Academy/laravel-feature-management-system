<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms;

/**
 * The quota arithmetic, as pure functions.
 *
 * Every decision this package makes about a metered feature reduces to one of
 * these, and they are static and framework-free so the shared
 * `shared/feature-entitlement` conformance table can hold this runtime, the
 * Node twin and the Python twin to identical answers. Cross-runtime behaviour
 * belongs in a fixture row, not in three sets of prose that agree today.
 *
 * ## Entitlement is not quota
 *
 * `entitled()` is deliberately blind to `includedQuantity` and `used`. Until
 * 0.11.0 the answer depended on where the feature happened to be defined: a
 * registry or config feature was on when its `enabled`/`check` said so, while a
 * catalog-backed one was on only while quota remained. One question, two
 * answers. `canConsume()` is the quota-aware read, and `Fms::tryIncrement()` is
 * the only one that is safe to gate a write with, because it takes the row lock.
 *
 * ## Everything here is a WHOLE UNIT
 *
 * A resource feature is counted, never measured. There is no fractional unit,
 * no rate and no proportional split anywhere in this class. Money enters only
 * when a host multiplies recorded overage units by a unit amount in minor
 * units, which this package never does.
 */
final class Quota
{
    /**
     * Is the subject entitled to the feature at all?
     *
     * `$includedQuantity` and `$used` are accepted and IGNORED. They are in the
     * signature because the conformance table hands them over and requires the
     * answer not to move — an implementation that starts consulting them has
     * re-merged two different questions, which is the defect this replaced.
     */
    public static function entitled(
        bool $enabled,
        string $type = 'boolean',
        ?int $includedQuantity = null,
        int $used = 0,
    ): bool {
        return $enabled;
    }

    /**
     * The highest total usage a subject may reach: the included quantity plus
     * whatever billable overage is permitted above it.
     *
     * `null` in means unlimited, and `null` out means the same — there is no
     * included line to exceed, so an overage allowance is meaningless and is
     * ignored rather than added to something.
     *
     * A `null` or `0` overage limit means NO overage. That is not an arbitrary
     * reading: the column was stored by three runtimes and consulted by none
     * until 0.11.0, so every row in every existing database is either null or a
     * number somebody typed hoping it would work. Reading null as "unbounded"
     * would turn each untouched row into an unlimited spending authority the
     * moment this shipped.
     */
    public static function consumptionCeiling(?int $includedQuantity, ?int $overageLimit): ?int
    {
        if ($includedQuantity === null) {
            return null;
        }

        return $includedQuantity + max(0, $overageLimit ?? 0);
    }

    /**
     * Does this request fit under the ceiling?
     *
     * All-or-nothing, on purpose. A request for 150 units against 100 remaining
     * is refused rather than partly filled: the answer is a boolean, so a caller
     * who got 100 has no way to learn that it did, and callers do not check
     * quantities they were never told about.
     *
     * `<=`, not `<`. A plan that says 100 has to permit the hundredth unit.
     */
    public static function allowsConsumption(int $used, int $amount, ?int $ceiling): bool
    {
        if ($ceiling === null) {
            return true;
        }

        return $used + $amount <= $ceiling;
    }

    /**
     * How many of the units in this consumption are BILLABLE OVERAGE.
     *
     * Signed on purpose: a refund passes a negative `$amount` and gets a
     * negative delta, so `increment()` and `decrement()` share one function and
     * cannot drift apart. The caller clamps the stored total at zero.
     *
     * Subtracting the overage that existed BEFORE the call is what makes this
     * composable over a period. The obvious `max(0, $after - $included)` is
     * wrong for a subject already in overage — it re-bills every unit already
     * recorded, every time.
     */
    public static function overageDelta(int $used, int $amount, ?int $includedQuantity): int
    {
        if ($includedQuantity === null) {
            return 0;
        }

        $after = $used + $amount;

        return max(0, $after - $includedQuantity) - max(0, $used - $includedQuantity);
    }

    /**
     * Entitled AND it fits — the quota-aware read.
     *
     * This is what `Fms::can()` answered before 0.11.0. It is a READ: between it
     * and the write that follows, another request can spend the last unit. Use
     * `Fms::tryIncrement()` to gate an actual consumption; use this to decide
     * what to show someone.
     */
    public static function canConsume(
        bool $enabled,
        ?int $includedQuantity,
        ?int $overageLimit,
        int $used,
        int $amount,
    ): bool {
        if (! $enabled) {
            return false;
        }

        return self::allowsConsumption(
            $used,
            $amount,
            self::consumptionCeiling($includedQuantity, $overageLimit),
        );
    }
}
