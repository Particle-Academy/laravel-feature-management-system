<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use ParticleAcademy\Fms\Models\FeatureUsage;

/**
 * Billable consumption past a plan's included quantity has been recorded.
 *
 * ## This is where the package stops
 *
 * FMS records overage. It does not invoice it, and that line is deliberate:
 * reporting metered usage to Stripe needs the *subscription item* id — the thing
 * that maps a subscription to one specific price — which this package does not
 * have, should not look up, and cannot know at all for an app metering something
 * Stripe never bills for. A package that guessed would be wrong in the expensive
 * direction for every app whose billing does not look like the one it was
 * written against.
 *
 * So this event carries everything an invoicer needs and the host owns the last
 * hop:
 *
 *     Event::listen(FeatureOverageRecorded::class, function ($event) {
 *         $item = $event->subscription->stripeItemFor($event->featureKey);
 *         Cashier::stripe()->billing->meterEvents->create([...]);
 *     });
 *
 * ## Fired per consumption, not per period
 *
 * `$units` is what THIS call added; `$totalUnits` is the running total for the
 * period. Providers differ on which they want — Stripe's meter events are
 * incremental, a subscription-item usage record can be either — so both are
 * here and neither has to be recomputed by a listener that would get it subtly
 * wrong.
 *
 * Only fired when `$units` is positive. A refund lowers the recorded total and
 * does not fire: a credit is a decision about money, and inventing one from a
 * usage correction is not this package's call.
 */
class FeatureOverageRecorded
{
    use Dispatchable;

    public function __construct(
        /** The host's subscription model instance. */
        public readonly mixed $subscription,
        /** The `product_features.key` this overage is against. */
        public readonly string $featureKey,
        /** The `product_features` row id, for a listener that needs the pivot. */
        public readonly mixed $productFeatureId,
        /** Billable units added by THIS consumption. Always > 0. */
        public readonly int $units,
        /** Total billable units recorded for the period, including `$units`. */
        public readonly int $totalUnits,
        /** The metering row, if a listener wants the period bounds or the raw usage. */
        public readonly FeatureUsage $usage,
        public readonly ?CarbonInterface $periodStart = null,
        public readonly ?CarbonInterface $periodEnd = null,
    ) {}
}
