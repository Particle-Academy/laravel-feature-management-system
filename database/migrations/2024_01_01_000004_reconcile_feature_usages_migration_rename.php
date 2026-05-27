<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the `create_feature_usages_table` migration rename across
 * older releases.
 *
 * Laravel's `migrations` table tracks migrations by filename, not by
 * file contents. The feature-usages migration has been shipped under
 * different filenames over the package's life:
 *
 *   2026_01_06_000000_create_feature_usages_table  (very early releases)
 *   2024_01_01_000003_create_feature_usages_table  (intermediate)
 *   2024_01_01_000005_create_feature_usages_table  (current, v0.5.1+)
 *
 * The v0.5.1 rename was a real fix — Postgres needed the file to sort
 * AFTER laravel-catalog's `product_features` so an FK reference would
 * resolve at apply time. But on existing installs the old filename is
 * stuck in the `migrations` table, and Laravel sees the new filename
 * as unrun and tries to CREATE TABLE again → either errors ("relation
 * already exists") or leaves a phantom row forever.
 *
 * This migration runs BEFORE the new filename (lower timestamp suffix)
 * and rewrites any stale row to the current name. Fresh installs see
 * no stale rows and this is a no-op. The actual table is left alone.
 *
 * Reported in https://github.com/Particle-Academy/laravel-feature-management-system/issues/2
 */
return new class extends Migration
{
    private const CURRENT = '2024_01_01_000005_create_feature_usages_table';

    /** @var list<string> */
    private const LEGACY = [
        '2026_01_06_000000_create_feature_usages_table',
        '2024_01_01_000003_create_feature_usages_table',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('migrations')) {
            return; // brand-new install, nothing to reconcile
        }

        $present = DB::table('migrations')->pluck('migration')->all();
        $hasCurrent = in_array(self::CURRENT, $present, true);

        foreach (self::LEGACY as $legacy) {
            if (! in_array($legacy, $present, true)) {
                continue;
            }

            if ($hasCurrent) {
                // Both names recorded (somehow). Drop the legacy row;
                // the current row is what subsequent runs will check.
                DB::table('migrations')->where('migration', $legacy)->delete();
            } else {
                // Only the legacy name is recorded. Rename it; the create
                // migration will then see CURRENT as already-run and skip.
                DB::table('migrations')
                    ->where('migration', $legacy)
                    ->update(['migration' => self::CURRENT]);
                $hasCurrent = true;
            }
        }
    }

    public function down(): void
    {
        // No-op: we can't tell which legacy filename to restore, and
        // restoring would only matter for installs that downgrade
        // through `php artisan migrate:rollback`, which they shouldn't
        // do across a rename.
    }
};
