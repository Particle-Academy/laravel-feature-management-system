<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ParticleAcademy\Fms\Database\FeatureUsagesSchema;

/**
 * Make `feature_usages.subscription_id` agree with the host's subscription key.
 *
 * FMS declared `foreignId('subscription_id')` — a bigint — while the package it
 * is paired with, `particle-academy/laravel-catalog`, is ULIDs throughout. So
 * the two halves of one feature disagreed about the type of the key that joins
 * them, and in a ULID-native app the create migration did not degrade, it
 * FAILED: a bigint foreign key cannot reference a `char(26)` primary key.
 *
 * From 0.11.0 the type is resolved (config first, then detection) by
 * `FeatureUsagesSchema`. This migration brings an existing table into line with
 * that resolution.
 *
 * ## Existing installs are a no-op, and that is not luck
 *
 * If your `feature_usages` table exists with a bigint `subscription_id`, your
 * subscriptions table is bigint too — it has to be, or the original foreign key
 * would never have been created. Detection agrees with what is there and this
 * migration does nothing.
 *
 * ## It refuses rather than hopes
 *
 * When the types disagree AND the table holds rows, this **throws**. There is no
 * automatic conversion from a bigint `5` to a ULID: only the application knows
 * how its old subscription ids map to its new ones, and a migration that guessed
 * would destroy metering history that bills people. The exception prints the row
 * count and the manual procedure; the full version is in
 * `.ai/plans/fancy-commerce-gating-rulings.md` §3.3 and in the CHANGELOG.
 *
 * ## down() is a no-op, deliberately
 *
 * It cannot know which type to restore, and narrowing a ULID column back to a
 * bigint is exactly the silent truncation this whole change refuses to perform.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = FeatureUsagesSchema::usagesTable();

        if (! Schema::hasTable($table)) {
            return; // nothing created yet; the create migration owns the shape
        }

        $current = FeatureUsagesSchema::currentSubscriptionShape();

        if ($current === null) {
            return; // no such column — a fork or a consumer-owned table
        }

        $desired = FeatureUsagesSchema::subscriptionKeyShape();

        if ($current['type'] === $desired['type']) {
            return; // already agrees
        }

        if (! Schema::hasTable(FeatureUsagesSchema::subscriptionsTable())) {
            // Nothing to reference and nothing to detect against. Leave the
            // table alone rather than rebuilding it from a guess.
            return;
        }

        $rows = DB::table($table)->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "laravel-fms: `{$table}.subscription_id` is a {$current['type']} but "
                ."`".FeatureUsagesSchema::subscriptionsTable().'.'.FeatureUsagesSchema::subscriptionKey()
                ."` is a {$desired['type']}, and the table holds {$rows} row(s) of metering "
                ."history.\n\n"
                ."This migration will not convert them: only your application knows how the old "
                ."subscription ids map to the new ones, and guessing would corrupt data that "
                ."bills people.\n\n"
                ."Do this instead:\n"
                ."  1. back up `{$table}`\n"
                ."  2. add a new column of the correct type and backfill it from your own mapping\n"
                ."  3. verify no row is left unmapped\n"
                ."  4. swap the columns, then re-run `php artisan migrate`\n\n"
                ."Or, if the existing type is right and the detection is wrong, set "
                ."`fms.subscription_key_type` to '{$current['type']}' and re-run. The full "
                ."procedure is in the 0.11.0 CHANGELOG entry."
            );
        }

        // Empty table: rebuild it. Dropping and recreating is what keeps this
        // working on SQLite, where dropping a constrained column rebuilds the
        // table anyway and fails if an index still references it.
        Schema::drop($table);
        FeatureUsagesSchema::create();
    }

    public function down(): void
    {
        // Intentionally empty. See the class docblock.
    }
};
