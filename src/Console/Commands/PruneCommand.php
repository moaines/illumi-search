<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\IndexManager;

class PruneCommand extends Command
{
    protected $signature = 'illumi-search:prune
        {--model= : Only prune a specific model class}
        {--max=50000 : Override max documents per model for this run (default: 50000, set 0 for unlimited)}';

    protected $description = 'Prune excess documents per model, keeping only the N most recent (highest model_id)';

    public function handle(Engine $engine): int
    {
        $indexManager = app(IndexManager::class);
        $models = $this->option('model')
            ? [(string) $this->option('model')]
            : $indexManager->getSearchableModels();

        $max = (int) $this->option('max');

        // --max=0 means unlimited (keep all documents)
        if ($max <= 0) {
            $this->warn('max_documents_per_model is 0 (unlimited) — nothing to prune.');

            return Command::SUCCESS;
        }

        $this->info("Pruning documents per model (keep last {$max})...");

        $totalDeleted = 0;
        foreach ($models as $modelClass) {
            if (! $engine->tableExists($modelClass)) {
                continue;
            }

            $deleted = $this->pruneModel($engine, $modelClass, $max);
            if ($deleted > 0) {
                $this->line("   {$modelClass}: pruned {$deleted} documents");
            }
            $totalDeleted += $deleted;
        }

        if ($totalDeleted === 0) {
            $this->info('All models within limit — nothing to prune.');
        } else {
            $this->info("Done. Pruned {$totalDeleted} documents total.");
        }

        return Command::SUCCESS;
    }

    private function pruneModel(Engine $engine, string $modelClass, int $max): int
    {
        if (method_exists($engine, 'pruneExcessDocuments')) {
            $before = $engine->count('', [$modelClass]);
            $engine->pruneExcessDocuments($modelClass);
            $after = $engine->count('', [$modelClass]);

            return max(0, $before - $after);
        }

        return 0;
    }
}
