<?php

namespace Moaines\IllumiSearch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Events\ModelIndexed;
use Moaines\IllumiSearch\Events\RebuildComplete;
use Moaines\IllumiSearch\Jobs\IndexBatchJob;

class IndexManager
{
    /** @var Collection<int, string>|null */
    protected static ?Collection $cachedModels = null;

    public function __construct(
        private readonly Engine $engine,
        private readonly TextProcessor $processor,
    ) {}

    /** @return Collection<int, string> */
    public function discoverModels(bool $refresh = false): Collection
    {
        if (static::$cachedModels !== null && ! $refresh) {
            return static::$cachedModels;
        }

        $models = collect();

        $paths = config('illumi-search.model_paths', [app_path('Models')]);

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

    /**
     * @param array<class-string>|null $modelClasses
     * @return array<int, array<string, mixed>>
     */
    public function rebuild(?array $modelClasses = null, ?int $batchSize = null, bool $vacuum = false, ?\Closure $progress = null): array
    {
        $models = $modelClasses !== null
            ? collect($modelClasses)
            : $this->discoverModels();

        $batchSize ??= (int) config('illumi-search.rebuild_batch_size', 0);
        $results = [];

        foreach ($models as $modelClass) {
            $result = $this->rebuildModel($modelClass, $batchSize, $progress);
            $results[] = $result;

            foreach (($result['warnings'] ?? []) as $w) {
                $results[] = $w;
            }

            event(new ModelIndexed($modelClass, $result['status'] === 'error' ? 0 : ($result['records'] ?? 0)));
        }

        event(new RebuildComplete($results));
        $results = $this->cleanupOrphans($models, $results);

        if (method_exists($this->engine, 'rebuildVocabFromScratch')) {
            $this->engine->rebuildVocabFromScratch();
        }

        if ($vacuum) {
            $this->engine->vacuum();
        }

        return $results;
    }

    /** @return array{model: string, status: string, records?: int, queued?: int, total?: int, message?: string, warnings?: array<int, array{model: string, status: string, message: string}>} */
    private function rebuildModel(string $modelClass, int $batchSize, ?\Closure $progress): array
    {
        if (! class_exists($modelClass)) {
            return ['model' => $modelClass, 'status' => 'error', 'message' => 'Class not found'];
        }

        if (! in_array('Moaines\\IllumiSearch\\Searchable', class_uses_recursive($modelClass))) {
            return ['model' => $modelClass, 'status' => 'skipped', 'message' => 'Not searchable'];
        }

        try {
            /** @var Model $instance */
            $instance = new $modelClass;
            $columns = $instance->getSearchableColumns();

            if (empty($columns)) {
                return ['model' => $modelClass, 'status' => 'skipped', 'message' => 'No searchable columns'];
            }

            $warningMessages = [];
            foreach ($instance->validateSearchable() as $w) {
                $warningMessages[] = ['model' => $modelClass, 'status' => 'warning', 'message' => $w];
            }

            $this->engine->dropTable($modelClass);
            $this->engine->createTable($modelClass, array_keys($columns), config('illumi-search.engines.sqlite.fts5.prefix_lengths', [2, 3, 4]));

            if (method_exists($this->engine, 'setRebuilding')) {
                $this->engine->setRebuilding(true);
            }

            $totalRecords = $modelClass::count();
            $keyName = (new $modelClass)->getKeyName();
            $relations = $instance->relationsForRebuild();
            $syncCount = 0;
            $queuedCount = 0;

            $progress?->__invoke('startModel', $modelClass, $totalRecords);

            if ($batchSize > 0 && $totalRecords > $batchSize) {
                $syncCount = $this->rebuildWithBatch($modelClass, $batchSize, $keyName, $relations, $progress, $queuedCount);
            } else {
                $syncCount = $this->rebuildSyncAll($modelClass, $keyName, $relations, $progress);
            }

            $progress?->__invoke('finishModel');

            if (method_exists($this->engine, 'setRebuilding')) {
                $this->engine->setRebuilding(false);
            }

            return [
                'model' => $modelClass,
                'status' => 'indexed',
                'records' => $syncCount,
                'queued' => $queuedCount,
                'total' => $totalRecords,
                'warnings' => $warningMessages,
            ];
        } catch (\Exception $e) {
            return ['model' => $modelClass, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @param string[] $relations */
    private function rebuildWithBatch(string $modelClass, int $batchSize, string $keyName, array $relations, ?\Closure $progress, int &$queuedCount): int
    {
        $records = $modelClass::query()
            ->orderBy($keyName)
            ->take($batchSize)
            ->get();

        if (! empty($relations)) $records->load($relations);

        $syncCount = $this->indexRecords($records, $modelClass);
        $progress?->__invoke('advance', $syncCount);

        $lastId = $records->last()?->getKey() ?? 0;
        $totalRecords = $modelClass::count();

        while ($syncCount + $queuedCount < $totalRecords) {
            $remaining = $totalRecords - ($syncCount + $queuedCount);
            $take = (int) min(100, $remaining);

            IndexBatchJob::dispatch(
                modelClass: $modelClass,
                lastId: $lastId,
                limit: $take,
            )->onConnection(config('illumi-search.queue_connection'));

            $queuedCount += $take;
            $lastId += $take;
        }

        return $syncCount;
    }

    /** @param string[] $relations */
    private function rebuildSyncAll(string $modelClass, string $keyName, array $relations, ?\Closure $progress): int
    {
        $syncCount = 0;

        $modelClass::query()
            ->chunkById(100, function ($records) use ($modelClass, &$syncCount, $relations, $progress) {
                if (! empty($relations)) $records->load($relations);
                $synced = $this->indexRecords($records, $modelClass);
                $syncCount += $synced;
                $progress?->__invoke('advance', $synced);
            }, $keyName);

        return $syncCount;
    }

    /**
     * @param Collection<int, string> $models
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function cleanupOrphans(Collection $models, array $results): array
    {
        $processedTables = $models->map(fn (string $cls): string => $this->engine->tableName($cls))->toArray();
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
            if ($isInternal || in_array($table, $processedTables, true)) continue;

            $this->engine->dropIndexTable($table);
            $results[] = ['model' => $table, 'status' => 'cleaned', 'message' => 'Orphaned index table removed'];
        }

        return $results;
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $records */
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

    /**
     * @param array<class-string>|null $modelClasses
     * @return array<int, array{model: string, status: string, records?: int, message?: string}>
     */
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
                $relations = $instance->relationsForRebuild();

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

    /** @return array<int, array{model: string, exists: bool, status: string, declared_columns: string[], indexed_columns: string[]}> */
    public function checkSchema(): array
    {
        $models = $this->discoverModels();
        $checks = [];

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $instance = new $modelClass;
            $declaredColumns = array_keys($instance->getSearchableColumns());
            $declaredColumns = array_map(
                fn ($col) => /** @scrutinizer ignore-call */ $instance->searchColumnName($col),
                $declaredColumns
            );
            /** @var list<string> $declaredColumns */
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

    /** @return array<int, array{model_class: string, record_count: int, last_synced_at: ?string, columns: ?string}> */
    public function status(): array
    {
        return $this->engine->getIndexStats();
    }

    protected function usesSearchable(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        return in_array('Moaines\\IllumiSearch\\Searchable', class_uses_recursive($class));
    }

    protected function getClassNameFromFile(string $path): ?string
    {
        $contents = File::get($path);

        if (! preg_match('/^namespace\s+(.+?);\s*$/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^(?:abstract\s+|final\s+|readonly\s+)*class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
