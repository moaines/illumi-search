<?php

namespace Moaines\LaravelFts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Events\ModelIndexed;
use Moaines\LaravelFts\Events\RebuildComplete;
use Moaines\LaravelFts\Jobs\IndexBatchJob;

class FtsIndexManager
{
    private static ?Collection $cachedModels = null;

    public function __construct(
        private readonly FtsEngine $engine,
        private readonly TextProcessor $processor,
    ) {}

    public function discoverModels(bool $refresh = false): Collection
    {
        if (static::$cachedModels !== null && ! $refresh) {
            return static::$cachedModels;
        }

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

        static::$cachedModels = $models->unique();

        return static::$cachedModels;
    }

    public function rebuild(?array $modelClasses = null, ?int $batchSize = null, bool $vacuum = false, ?\Closure $progress = null): array
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

                $warnings = $instance->validateFtsSearchable();
                foreach ($warnings as $w) {
                    $results[] = ['model' => $modelClass, 'status' => 'warning', 'message' => $w];
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
                $relations = $instance->ftsRelationsForRebuild();

                $progress?->__invoke('startModel', $modelClass, $totalRecords);

                $syncCount = 0;
                $queuedCount = 0;

                if ($batchSize > 0 && $totalRecords > $batchSize) {
                    // Sync first batch manually (without chunk, to respect the limit)
                    $records = $modelClass::query()
                        ->orderBy($keyName)
                        ->take($batchSize)
                        ->get();

                    if (! empty($relations)) {
                        $records->load($relations);
                    }

                    $syncCount = $this->indexRecords($records, $modelClass);
                    $progress?->__invoke('advance', $syncCount);

                    // Queue remaining as IndexBatchJob (each job handles up to 100)
                    $lastId = $records->last()?->getKey() ?? 0;
                    while ($syncCount + $queuedCount < $totalRecords) {
                        $remaining = $totalRecords - ($syncCount + $queuedCount);
                        $take = min(100, $remaining);

                        IndexBatchJob::dispatch(
                            modelClass: $modelClass,
                            lastId: $lastId,
                            limit: $take,
                        );

                        $queuedCount += $take;
                        $lastId += $take; // approximate — the job adjusts via where(>)
                    }
                } else {
                    // Sync all
                    $modelClass::query()
                        ->chunkById(100, function ($records) use ($modelClass, &$syncCount, $relations, $progress) {
                            if (! empty($relations)) {
                                $records->load($relations);
                            }
                            $synced = $this->indexRecords($records, $modelClass);
                            $syncCount += $synced;
                            $progress?->__invoke('advance', $synced);
                        }, $keyName);
                }

                $progress?->__invoke('finishModel');

                $results[] = [
                    'model' => $modelClass,
                    'status' => 'indexed',
                    'records' => $syncCount,
                    'queued' => $queuedCount,
                    'total' => $totalRecords,
                ];

                event(new ModelIndexed($modelClass, $syncCount));
            } catch (\Exception $e) {
                $results[] = ['model' => $modelClass, 'status' => 'error', 'message' => $e->getMessage()];

                event(new ModelIndexed($modelClass, 0));
            }
        }

        event(new RebuildComplete($results));

        // Clean up orphaned index tables (tables for models that no longer use Searchable)
        $processedTables = $models->map(fn ($cls) => $this->engine->tableName($cls))->toArray();
        $existingTables = $this->engine->listIndexTables();

        $internalSuffixes = ['_content', '_data', '_docsize', '_idx', '_config', '_vocab'];

        foreach ($existingTables as $table) {
            $isInternal = false;
            foreach ($internalSuffixes as $suffix) {
                if (str_ends_with($table, $suffix)) {
                    $isInternal = true;
                    break;
                }
            }
            if ($isInternal) {
                continue;
            }

            if (in_array($table, $processedTables, true)) {
                continue;
            }

            $this->engine->dropIndexTable($table);
            $results[] = ['model' => $table, 'status' => 'cleaned', 'message' => 'Orphaned index table removed'];
        }

        if ($vacuum) {
            $this->engine->vacuum();
        }

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

    public function sync(?array $modelClasses = null, ?\DateTimeInterface $since = null, ?\Closure $progress = null): array
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
                $instance = new $modelClass;
                $keyName = $instance->getKeyName();
                $query = $modelClass::query();

                if ($since !== null) {
                    $query->where($instance->getUpdatedAtColumn(), '>=', $since);
                }

                $count = 0;
                $total = (clone $query)->count();
                $relations = $instance->ftsRelationsForRebuild();

                $progress?->__invoke('startModel', $modelClass, $total);

                $query->chunkById(100, function ($records) use ($modelClass, &$count, $relations, $progress) {
                    if (! empty($relations)) {
                        $records->load($relations);
                    }
                    foreach ($records as $record) {
                        $processed = $record->processDocument($record, $this->processor);
                        $this->engine->upsert($modelClass, $record->getKey(), $processed);
                        $count++;
                    }
                    $progress?->__invoke('advance', $records->count());
                });

                $progress?->__invoke('finishModel');

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
