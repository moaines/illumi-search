<?php

namespace Moaines\LaravelFts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Jobs\IndexBatchJob;

class FtsIndexManager
{
    public function __construct(
        private readonly FtsEngine $engine,
        private readonly TextProcessor $processor,
    ) {}

    public function discoverModels(): Collection
    {
        $models = collect();

        $paths = config('fts.model_paths', [app_path('Models')]);

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = File::allFiles($path);

            foreach ($files as $file) {
                $class = $this->getClassNameFromFile($file->getPathname());

                if ($class === null) {
                    continue;
                }

                if ($this->usesSearchable($class)) {
                    $models->push($class);
                }
            }
        }

        return $models->unique();
    }

    public function rebuild(?array $modelClasses = null, ?int $batchSize = null): array
    {
        $models = $modelClasses !== null
            ? collect($modelClasses)
            : $this->discoverModels();

        $batchSize ??= (int) config('fts.rebuild_batch_size', 0);
        $results = [];

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                $results[] = ['model' => $modelClass, 'status' => 'error', 'message' => 'Class not found'];

                continue;
            }

            if (! in_array('Moaines\\LaravelFts\\Searchable', class_uses_recursive($modelClass))) {
                $results[] = ['model' => $modelClass, 'status' => 'skipped', 'message' => 'Not searchable'];

                continue;
            }

            try {
                /** @var Model $instance */
                $instance = new $modelClass;
                $columns = $instance->getFtsSearchableColumns();

                if (empty($columns)) {
                    $results[] = ['model' => $modelClass, 'status' => 'skipped', 'message' => 'No searchable columns'];

                    continue;
                }

                $this->engine->dropTable($modelClass);

                $prefixLengths = config('fts.fts5.prefix_lengths', [2, 3, 4]);

                $this->engine->createTable(
                    $modelClass,
                    array_keys($columns),
                    $prefixLengths
                );

                $totalRecords = $modelClass::count();
                $keyName = (new $modelClass)->getKeyName();

                $syncCount = 0;
                $queuedCount = 0;

                if ($batchSize > 0 && $totalRecords > $batchSize) {
                    // Sync first batch manually (without chunk, to respect the limit)
                    $records = $modelClass::query()
                        ->orderBy($keyName)
                        ->take($batchSize)
                        ->get();

                    $syncCount = $this->indexRecords($records, $modelClass);

                    // Queue remaining as IndexBatchJob (each job handles up to 100)
                    $processed = $batchSize;
                    while ($processed < $totalRecords) {
                        $remaining = $totalRecords - $processed;
                        $take = min(100, $remaining);

                        IndexBatchJob::dispatch(
                            modelClass: $modelClass,
                            offset: $processed,
                            limit: $take,
                        );

                        $queuedCount += $take;
                        $processed += $take;
                    }
                } else {
                    // Sync all
                    $modelClass::query()
                        ->chunkById(100, function ($records) use ($modelClass, &$syncCount) {
                            $syncCount += $this->indexRecords($records, $modelClass);
                        }, $keyName);
                }

                $results[] = [
                    'model' => $modelClass,
                    'status' => 'indexed',
                    'records' => $syncCount,
                    'queued' => $queuedCount,
                    'total' => $totalRecords,
                ];
            } catch (\Exception $e) {
                $results[] = ['model' => $modelClass, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $this->engine->vacuum();

        return $results;
    }

    private function indexRecords($records, string $modelClass): int
    {
        $documents = [];
        $count = 0;

        foreach ($records as $record) {
            $documents[] = [
                'model_id' => $record->getKey(),
                'document' => $record->processDocument($record, $this->processor),
            ];
            $count++;
        }

        if (! empty($documents)) {
            $this->engine->insertBatch($modelClass, $documents);
        }

        return $count;
    }

    public function sync(?array $modelClasses = null, ?\DateTimeInterface $since = null): array
    {
        $models = $modelClasses !== null
            ? collect($modelClasses)
            : $this->discoverModels();

        $results = [];

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            try {
                $keyName = (new $modelClass)->getKeyName();
                $query = $modelClass::query();

                if ($since !== null) {
                    $query->where('updated_at', '>=', $since);
                }

                $count = 0;
                $query->chunkById(100, function ($records) use ($modelClass, &$count) {
                    foreach ($records as $record) {
                        $processed = $record->processDocument($record, $this->processor);
                        $this->engine->upsert($modelClass, $record->getKey(), $processed);
                        $count++;
                    }
                });

                $results[] = ['model' => $modelClass, 'status' => 'synced', 'records' => $count];
            } catch (\Exception $e) {
                $results[] = ['model' => $modelClass, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function checkSchema(): array
    {
        $models = $this->discoverModels();
        $checks = [];

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $modelClass;
            $declaredColumns = array_keys($instance->getFtsSearchableColumns());
            sort($declaredColumns);

            $exists = $this->engine->tableExists($modelClass);
            $indexedColumns = [];

            if ($exists) {
                $stats = $this->engine->getIndexStats();
                foreach ($stats as $stat) {
                    if ($stat['model_class'] === $modelClass) {
                        $indexedColumns = json_decode($stat['columns'] ?? '[]', true) ?? [];
                        sort($indexedColumns);
                        break;
                    }
                }
            }

            $status = 'ok';
            if (! $exists) {
                $status = 'missing';
            } elseif ($declaredColumns !== $indexedColumns) {
                $status = 'drift';
            }

            $checks[] = [
                'model' => $modelClass,
                'exists' => $exists,
                'status' => $status,
                'declared_columns' => $declaredColumns,
                'indexed_columns' => $indexedColumns,
            ];
        }

        return $checks;
    }

    public function status(): array
    {
        return $this->engine->getIndexStats();
    }

    protected function usesSearchable(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        return in_array('Moaines\\LaravelFts\\Searchable', class_uses_recursive($class));
    }

    protected function getClassNameFromFile(string $path): ?string
    {
        $contents = File::get($path);

        if (! preg_match('/^namespace\s+(.+?);\s*$/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
