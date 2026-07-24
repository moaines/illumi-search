<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasProgressBar;
use Moaines\IllumiSearch\IndexManager;

class RebuildCommand extends Command
{
    use HasProgressBar;

    protected $signature = 'illumi-search:rebuild
        {--model=* : Specific model classes to rebuild (multiple allowed)}
        {--force : Skip confirmation prompt}
        {--vacuum : Run VACUUM after rebuilding (slower but reclaims disk space)}
        {--batch-size= : Records to sync before switching to queue (default: config)}';
    protected $description = 'Rebuild the search index from scratch';

    public function handle(IndexManager $manager): int
    {
        // FileEngine needs more memory for large datasets
        if (config('illumi-search.driver') === 'file') {
            $current = ini_get('memory_limit');
            if ($current !== '-1' && $this->parseMemory($current) < 256) {
                ini_set('memory_limit', '256M');
                $this->line('   <fg=yellow>ℹ memory_limit increased to 256M for file engine</>');
            }
        }
        $models = $this->option('model');

        if (empty($models) && ! $this->option('force')) {
            if (! $this->confirm('This will rebuild ALL indexed models. Continue?')) {
                $this->info('Rebuild cancelled.');

                return Command::SUCCESS;
            }
        }

        $batchSize = $this->option('batch-size');
        if ($batchSize !== null) {
            $batchSize = (int) $batchSize;
        }

        $vacuum = (bool) $this->option('vacuum');

        if (! empty($models)) {
            $this->info('Rebuilding specific models: ' . implode(', ', $models));
        } else {
            $this->info('Rebuilding all indexed models...');
        }

        $pb = null;

        $results = $manager->rebuild(
            modelClasses: ! empty($models) ? $models : null,
            batchSize: $batchSize,
            vacuum: $vacuum,
            progress: function (string $event, ...$args) use (&$pb) {
                match ($event) {
                    'startModel' => $this->startProgressBar($pb, $args[0], $args[1]),
                    'advance' => $pb?->advance($args[0]),
                    'finishModel' => $this->finishProgressBar($pb),
                    default => null,
                };
            },
        );

        $this->clearProgressBar($pb);

        foreach ($results as $result) {
            match ($result['status']) {
                'indexed' => $this->indexedResult($result),
                'skipped' => $this->warn("  - {$result['model']}: {$result['message']}"),
                'error' => $this->error("  ✗ {$result['model']}: {$result['message']}"),
                default => $this->line("  ? {$result['model']}: unknown status"),
            };
        }

        $this->newLine();
        $this->info('Rebuild complete.');

        return Command::SUCCESS;
    }

    private function parseMemory(string $setting): int
    {
        $value = (int) $setting;
        $unit = strtolower(substr(trim($setting), -1));

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }

    /** @param array{model: string, records?: int, queued?: int, total?: int, message?: string} $result */
    private function indexedResult(array $result): void
    {
        $total = $result['total'] ?? $result['records'] ?? 0;
        $synced = $result['records'] ?? 0;
        $status = $synced === $total ? '✓' : '...';

        $this->line("  {$status} {$result['model']}: {$synced} records indexed (total: {$total})");
    }
}
