<?php

namespace ParticleAcademy\Fms\Commands;

use ParticleAcademy\Fms\Services\FmsFeatureGroupRegistry;
use Illuminate\Console\Command;

/**
 * `php artisan fms:groups [{key?}]`
 *
 * No args  → tabular list of all registered groups + their feature counts.
 * With key → detailed view of one group (resolved features, overrides,
 *            extends chain, callable-gate presence).
 */
class FmsGroupsCommand extends Command
{
    protected $signature = 'fms:groups {key? : Optional group key to inspect}';

    protected $description = 'List FMS feature groups, or inspect one in detail';

    public function handle(FmsFeatureGroupRegistry $groups): int
    {
        $key = $this->argument('key');

        if ($key === null) {
            return $this->listAll($groups);
        }

        return $this->inspectOne($groups, $key);
    }

    protected function listAll(FmsFeatureGroupRegistry $groups): int
    {
        $all = $groups->all();
        if (empty($all)) {
            $this->components->info('No feature groups registered. Define them under `groups` in config/fms.php.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($all as $key => $group) {
            $resolved = $groups->resolvedFeatures($key);
            $rows[] = [
                $key,
                $group->name ?? '—',
                count($resolved),
                $group->extends ? implode(', ', $group->extends) : '—',
                $group->enabled !== null ? 'yes' : 'no',
            ];
        }

        $this->table(
            ['Key', 'Name', 'Features', 'Extends', 'Has Gate'],
            $rows
        );

        return self::SUCCESS;
    }

    protected function inspectOne(FmsFeatureGroupRegistry $groups, string $key): int
    {
        $group = $groups->get($key);
        if ($group === null) {
            $this->components->error("Feature group `{$key}` not found.");
            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <fg=cyan;options=bold>{$key}</>" . ($group->name ? " — {$group->name}" : ''));
        if ($group->description) {
            $this->line("  <fg=gray>{$group->description}</>");
        }
        $this->line('');

        $this->components->twoColumnDetail('Extends', $group->extends ? implode(', ', $group->extends) : '—');
        $this->components->twoColumnDetail('Has callable gate', $group->enabled !== null ? 'yes' : 'no');

        $resolved = $groups->resolvedFeatures($key);
        $this->line('');
        $this->components->info('Features (resolved with extends):');
        foreach ($resolved as $feature) {
            $this->line("  • {$feature}");
        }
        if (empty($resolved)) {
            $this->line('  <fg=gray>(none)</>');
        }

        $overrides = $groups->resolvedOverrides($key);
        if (!empty($overrides)) {
            $this->line('');
            $this->components->info('Overrides:');
            foreach ($overrides as $feature => $override) {
                $this->line("  • {$feature}: " . json_encode($override, JSON_UNESCAPED_SLASHES));
            }
        }
        $this->line('');

        return self::SUCCESS;
    }
}
