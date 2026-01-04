<?php

namespace ParticleAcademy\Fms\Commands;

use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use Illuminate\Console\Command;

/**
 * Sync FMS features command
 * Why: Syncs code-defined FMS features into the database. By default, only
 * creates new features to preserve admin customizations. Use --feature to
 * force-sync a specific feature when code changes require it.
 */
class SyncFmsFeatures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fms:sync
                            {--feature= : Force sync a specific feature by key (overwrites existing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync code-defined FMS features into the product_features catalog.';

    /**
     * Execute the console command.
     */
    public function handle(FmsFeatureRegistry $registry): int
    {
        $featureKey = $this->option('feature');

        if ($featureKey) {
            // Force sync a specific feature (overwrites existing)
            $synced = $registry->forceSyncFeature($featureKey);

            if ($synced) {
                $this->info("Force synced feature '{$featureKey}'.");
            } else {
                $this->error("Feature '{$featureKey}' not found in config/fms.php definitions.");

                return self::FAILURE;
            }
        } else {
            // Default: only sync new features (preserve existing customizations)
            $result = $registry->syncNewFeatures();

            if ($result['created'] > 0) {
                $this->info("Created {$result['created']} new FMS feature(s).");
            }

            if ($result['skipped'] > 0) {
                $this->line("<fg=gray>Skipped {$result['skipped']} existing feature(s) (use --feature=<key> to force sync).</>");
            }

            if ($result['created'] === 0 && $result['skipped'] === 0) {
                $this->info('No features to sync.');
            }
        }

        return self::SUCCESS;
    }
}

