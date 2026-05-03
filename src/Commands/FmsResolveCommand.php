<?php

namespace ParticleAcademy\Fms\Commands;

use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use ParticleAcademy\Fms\Services\FeatureManager;
use ParticleAcademy\Fms\Services\FmsFeatureGroupRegistry;
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;
use Illuminate\Console\Command;
use Illuminate\Foundation\Auth\User;

/**
 * `php artisan fms:resolve {subject} [--type=Class] [--feature=key]`
 *
 * Explains why a feature is on/off for a given subject. Without --feature
 * walks every registered feature; with --feature drills into one. Output
 * shows the resolution source (gate/registry/group/config/none), the
 * verdict, and structured detail (matching groups, limit overrides, etc.).
 */
class FmsResolveCommand extends Command
{
    protected $signature = 'fms:resolve
                            {subject : Subject id (e.g. user id) to resolve features against}
                            {--type= : Fully qualified class for the subject (defaults to App\\Models\\User)}
                            {--feature= : Resolve a single feature instead of all}';

    protected $description = 'Trace why each feature is on/off for a given subject';

    public function handle(
        FeatureManagerInterface $manager,
        FmsFeatureRegistry $features,
        FmsFeatureGroupRegistry $groups,
    ): int {
        $type = $this->option('type') ?: User::class;
        if (!class_exists($type)) {
            $this->components->error("Subject class `{$type}` does not exist.");
            return self::FAILURE;
        }

        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = $type::find($this->argument('subject'));
        if (!$instance) {
            $this->components->error("Subject `{$type}#{$this->argument('subject')}` not found.");
            return self::FAILURE;
        }

        $featureKeys = $this->collectFeatureKeys($features, $groups);
        if ($single = $this->option('feature')) {
            $featureKeys = [$single];
        }

        if (!$manager instanceof FeatureManager) {
            $this->components->warn('FeatureManager binding has been replaced — explain() may not be available.');
        }

        $rows = [];
        foreach ($featureKeys as $feature) {
            $explanation = method_exists($manager, 'explain')
                ? $manager->explain($feature, $instance)
                : ['feature' => $feature, 'source' => 'unknown', 'enabled' => $manager->canAccess($feature, $instance), 'detail' => []];

            $rows[] = [
                $feature,
                $explanation['enabled'] ? '<fg=green>on</>' : '<fg=red>off</>',
                $explanation['source'],
                $this->formatDetail($explanation['detail']),
            ];
        }

        $this->table(
            ['Feature', 'State', 'Source', 'Detail'],
            $rows
        );

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    protected function collectFeatureKeys(FmsFeatureRegistry $features, FmsFeatureGroupRegistry $groups): array
    {
        $keys = array_keys($features->all());
        foreach (array_keys(config('fms.features', [])) as $key) {
            $keys[] = $key;
        }
        foreach ($groups->all() as $groupKey => $_group) {
            foreach ($groups->resolvedFeatures($groupKey) as $featureKey) {
                $keys[] = $featureKey;
            }
        }
        return array_values(array_unique($keys));
    }

    protected function formatDetail(array $detail): string
    {
        if (empty($detail)) {
            return '—';
        }
        if (isset($detail['groups'])) {
            $groups = implode(', ', $detail['groups']);
            $limit = $detail['limit_override'] ?? null;
            return "via groups [{$groups}]" . ($limit !== null ? " — limit override = {$limit}" : '');
        }
        return json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
}
