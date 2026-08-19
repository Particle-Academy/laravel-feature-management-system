<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ParticleAcademy\Fms\Database\FeatureUsagesSchema;

/**
 * Somewhere to put billable overage.
 *
 * `product_feature_configs.overage_limit` has existed since the first release
 * and was read by nothing — in PHP, in Node, or in Python. From 0.11.0 it is a
 * ceiling on billable consumption past `included_quantity`, and the units above
 * that line have to be recorded or they cannot reach an invoice.
 *
 * ## Recorded, not derived
 *
 * `overage = max(0, used_quantity - included_quantity)` computed at read time
 * is one column cheaper and quietly wrong. When a customer upgrades mid-period
 * the included quantity rises, and overage that was genuinely incurred — and
 * possibly already reported to a billing provider — silently disappears from the
 * derivation. Overage is a fact about what happened, so it is written down when
 * it happens.
 *
 * ## Nothing to back up, nothing to backfill
 *
 * The column defaults to 0 and every existing row is correct at 0: with
 * `overage_limit` read by nothing until now, no consumption past the included
 * quantity was ever permitted, so no historical overage exists to reconstruct.
 * Adding it changes no behaviour on its own — the behaviour change is in
 * `Fms`, and it is opt-in per pivot row, because a null `overage_limit` means
 * no overage.
 *
 * Fully reversible: dropping the column loses recorded overage, which is why
 * `down()` says so rather than pretending otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = FeatureUsagesSchema::usagesTable();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'overage_quantity')) {
            // Absent table: the create migration deferred to the consumer's own,
            // and theirs owns the column. Column present: a fresh install got it
            // from the create migration, or this already ran.
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('overage_quantity')->default(0)->after('used_quantity');
        });
    }

    /**
     * Reverse the migration.
     *
     * This DISCARDS recorded billable overage. There is nowhere else it is
     * stored and no way to recompute it after the fact — see the note above on
     * why deriving it is wrong. Take a dump of the table first if the rollback
     * is anything other than an immediate undo of a failed deploy.
     */
    public function down(): void
    {
        $table = FeatureUsagesSchema::usagesTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'overage_quantity')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('overage_quantity');
        });
    }
};
