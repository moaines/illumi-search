<?php

namespace Moaines\IllumiSearch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Jobs\DeleteIndexJob;
use Moaines\IllumiSearch\Jobs\IndexModelJob;

trait Searchable
{
    public static function bootSearchable(): void
    {
        $indexing = config('illumi-search.indexing', 'queue');

        if ($indexing === 'manual') {
            return;
        }

        $queue = config('illumi-search.queue_connection');

        $indexOnSave = function (Model $model) use ($indexing, $queue): void {
            if ($model->shouldSync()) {
                if ($indexing === 'queue') {
                    dispatch(new IndexModelJob($model::class, $model->getKey()))
                        ->afterCommit()
                        ->onConnection($queue);
                } else {
                    static::syncToSearch($model);
                }
            }
        };

        static::saved($indexOnSave);

        static::deleted(function (Model $model) use ($indexing, $queue) {
            if ($indexing === 'queue') {
                dispatch(new DeleteIndexJob($model::class, $model->getKey()))
                    ->afterCommit()
                    ->onConnection($queue);
            } else {
                app(Engine::class)->delete($model::class, $model->getKey());
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored($indexOnSave);
        }
    }

    public function shouldSync(): bool
    {
        if (! ($this->syncOnSave ?? true)) {
            return false;
        }

        if (method_exists($this, 'trashed') && $this->trashed()) {
            return false;
        }

        return true;
    }

    public function searchColumnName(string $column): string
    {
        return str_replace(['.', '->', '-'], '_', $column);
    }

    public function toSearchDocument(): array
    {
        $columns = $this->normalizeSearchable();
        $document = [];

        foreach ($columns as $column => $config) {
            $safeName = $this->searchColumnName($column);
            $document[$safeName] = $this->resolveSearchValue($column);
        }

        return $document;
    }

    public function resolveSearchValue(string $column): string
    {
        try {
            if (! str_contains($column, '.')) {
                return (string) ($this->$column ?? $this->getAttribute($column) ?? '');
            }

            $segments = explode('.', $column);
            $last = array_pop($segments);
            $related = $this;

            foreach ($segments as $segment) {
                $related = $related?->$segment;
                if ($related === null) {
                    return '';
                }
            }

            if ($related instanceof Collection || $related instanceof \Illuminate\Database\Eloquent\Collection) {
                $max = config('illumi-search.max_related_values', 100);

                return $related->pluck($last)->filter()->take($max)->implode(' ');
            }

            return (string) ($related->$last ?? $related->getAttribute($last) ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function relationsForRebuild(): array
    {
        $relations = [];
        foreach ($this->getSearchableColumns() as $key => $config) {
            $colName = is_string($config) ? $config : $key;
            $firstDot = strpos($colName, '.');
            if ($firstDot === false) {
                continue;
            }
            $relPath = substr($colName, 0, strrpos($colName, '.'));
            if (! in_array($relPath, $relations, true)) {
                $relations[] = $relPath;
            }
        }

        return $relations;
    }

    public function validateSearchable(): array
    {
        $warnings = [];

        foreach ($this->getSearchableColumns() as $key => $config) {
            $colName = is_string($config) ? $config : $key;

            if (! str_contains($colName, '.')) {
                continue;
            }

            $relName = explode('.', $colName)[0];

            if (! method_exists($this, $relName)) {
                $warnings[] = class_basename(static::class)
                    ."::\$searchable: relation '{$relName}' introuvable pour '{$colName}'";
            }
        }

        return $warnings;
    }

    public function searchUrl(): ?string
    {
        return null;
    }

    public function searchCategory(): ?string
    {
        if (isset($this->searchCategory)) {
            return $this->searchCategory;
        }

        return Str::plural(class_basename(static::class));
    }

    public function getSearchableColumns(): array
    {
        return $this->searchable ?? [];
    }

    public function searchTextProcessor(): ?string
    {
        return null;
    }

    public static function resolveProcessorFor(Model $model, TextProcessor $global): TextProcessor
    {
        $customClass = $model->searchTextProcessor();

        if ($customClass !== null && class_exists($customClass)) {
            return app($customClass);
        }

        return $global;
    }

    /**
     * Normalize $searchable to a consistent ['column' => [...config...]] format.
     * Accepts:
     *   ['title' => ['weight' => 3]]   → explicit config
     *   ['author' => true]             → shorthand (default weight)
     *   ['author']                     → minimal (no weight, no config)
     */
    public function normalizeSearchable(): array
    {
        $raw = $this->getSearchableColumns();

        if (empty($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $column = $key;
                $config = is_array($value) ? $value : [];
            } else {
                $column = $value;
                $config = [];
            }
            $normalized[$column] = $config;
        }

        return $normalized;
    }

    /**
     * Process a model's document through the appropriate TextProcessor.
     * Respects per-column locale configuration.
     */
    public static function processDocument(Model $model, TextProcessor $global): array
    {
        $columns = $model->normalizeSearchable();
        $doc = $model->toSearchDocument();
        $processor = static::resolveProcessorFor($model, $global);
        $processed = [];

        foreach ($columns as $column => $config) {
            $safeName = $model->searchColumnName($column);
            $value = $doc[$safeName] ?? '';
            $locale = $config['locale'] ?? app()->getLocale() ?? 'en';
            $processed[$column] = $processor->process((string) $value, $locale);
        }

        return $processed;
    }

    protected static array $checkedTables = [];

    public static function syncToSearch(Model $model): void
    {
        $engine = app(Engine::class);
        $global = app(TextProcessor::class);
        $class = $model::class;

        if (! isset(static::$checkedTables[$class])) {
            static::$checkedTables[$class] = $engine->tableExists($class);
        }

        if (! static::$checkedTables[$class]) {
            logger()->debug('illumi-search: skipped sync for {class} — FTS5 table not yet created.', ['class' => $class]);

            return;
        }

        $processed = static::processDocument($model, $global);
        $engine->upsert($class, $model->getKey(), $processed);
    }
}
