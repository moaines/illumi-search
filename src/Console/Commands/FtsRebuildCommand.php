<?php

namespace Moaines\LaravelFts\Console\Commands;

use Illuminate\Console\Command;
use Moaines\LaravelFts\FtsIndexManager;

class FtsRebuildCommand extends Command
{
    protected $signature = 'fts:rebuild
        {--model=* : Specific model classes to rebuild (multiple allowed)}
        {--force : Skip confirmation prompt}
        {--mode= : Override search mode}
        {--batch-size= : Records to sync before switching to queue (default: config)}';

    protected $description = 'Rebuild the FTS5 search index from scratch';

    public function handle(FtsIndexManager $manager): int
    {
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

        if (! empty($models)) {
            $this->info('Rebuilding specific models: '.implode(', ', $models));
        } else {
            $this->info('Rebuilding all indexed models...');
        }

        $results = $manager->rebuild(
            modelClasses: ! empty($models) ? $models : null,
            batchSize: $batchSize,
        );

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

    private function indexedResult(array $result): void
    {
        $msg = "  ✓ {$result['model']}: {$result['records']} records indexed";

        if (($result['queued'] ?? 0) > 0) {
            $msg .= ", {$result['queued']} dispatched to queue (total: {$result['total']})";
        } elseif (isset($result['total'])) {
            $msg .= " (total: {$result['total']})";
        }

        $this->info($msg);
    }
}
