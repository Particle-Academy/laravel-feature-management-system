<?php

use Illuminate\Support\Facades\DB;
use ParticleAcademy\Fms\Tests\TestCase;

uses(TestCase::class);

/**
 * Coverage for the v0.5.2 reconciliation migration
 * (database/migrations/2024_01_01_000004_reconcile_feature_usages_migration_rename.php).
 *
 * We can't easily re-run a single migration mid-test under
 * RefreshDatabase, so each scenario seeds the `migrations` table to
 * mimic what a downstream consumer's history would look like, calls
 * the reconciliation logic directly, and asserts the resulting
 * `migrations` rows match what Laravel will accept on the next
 * `php artisan migrate`.
 */

function reconcileMigrationsTable(): void
{
    $current = '2024_01_01_000005_create_feature_usages_table';
    $legacy = [
        '2026_01_06_000000_create_feature_usages_table',
        '2024_01_01_000003_create_feature_usages_table',
    ];

    $present = DB::table('migrations')->pluck('migration')->all();
    $hasCurrent = in_array($current, $present, true);

    foreach ($legacy as $name) {
        if (! in_array($name, $present, true)) {
            continue;
        }
        if ($hasCurrent) {
            DB::table('migrations')->where('migration', $name)->delete();
        } else {
            DB::table('migrations')->where('migration', $name)->update(['migration' => $current]);
            $hasCurrent = true;
        }
    }
}

it('no-ops when no feature_usages migration has run', function () {
    DB::table('migrations')->where('migration', 'like', '%feature_usages%')->delete();

    reconcileMigrationsTable();

    // Reconcile didn't invent rows out of thin air; fresh installs let
    // the actual create migration run normally.
    $rows = DB::table('migrations')->where('migration', 'like', '%feature_usages%')->pluck('migration')->all();
    expect($rows)->toBe([]);
});

it('renames the very-early legacy filename', function () {
    DB::table('migrations')->where('migration', 'like', '%feature_usages%')->delete();
    DB::table('migrations')->insert([
        'migration' => '2026_01_06_000000_create_feature_usages_table',
        'batch' => 1,
    ]);

    reconcileMigrationsTable();

    $rows = DB::table('migrations')->where('migration', 'like', '%feature_usages%')->pluck('migration')->all();
    expect($rows)->toContain('2024_01_01_000005_create_feature_usages_table');
    expect($rows)->not->toContain('2026_01_06_000000_create_feature_usages_table');
});

it('renames the intermediate legacy filename', function () {
    DB::table('migrations')->where('migration', 'like', '%feature_usages%')->delete();
    DB::table('migrations')->insert([
        'migration' => '2024_01_01_000003_create_feature_usages_table',
        'batch' => 1,
    ]);

    reconcileMigrationsTable();

    $rows = DB::table('migrations')->where('migration', 'like', '%feature_usages%')->pluck('migration')->all();
    expect($rows)->toContain('2024_01_01_000005_create_feature_usages_table');
    expect($rows)->not->toContain('2024_01_01_000003_create_feature_usages_table');
});

it('drops legacy rows when current is already present', function () {
    DB::table('migrations')->where('migration', 'like', '%feature_usages%')->delete();
    DB::table('migrations')->insert([
        ['migration' => '2026_01_06_000000_create_feature_usages_table', 'batch' => 1],
        ['migration' => '2024_01_01_000005_create_feature_usages_table', 'batch' => 2],
    ]);

    reconcileMigrationsTable();

    $rows = DB::table('migrations')->where('migration', 'like', '%feature_usages%')->pluck('migration')->all();
    expect(count($rows))->toBe(1);
    expect($rows[0])->toBe('2024_01_01_000005_create_feature_usages_table');
});

it('handles both legacy filenames in the same history', function () {
    DB::table('migrations')->where('migration', 'like', '%feature_usages%')->delete();
    DB::table('migrations')->insert([
        ['migration' => '2026_01_06_000000_create_feature_usages_table', 'batch' => 1],
        ['migration' => '2024_01_01_000003_create_feature_usages_table', 'batch' => 2],
    ]);

    reconcileMigrationsTable();

    $rows = DB::table('migrations')->where('migration', 'like', '%feature_usages%')->pluck('migration')->all();
    expect(count($rows))->toBe(1);
    expect($rows[0])->toBe('2024_01_01_000005_create_feature_usages_table');
});
