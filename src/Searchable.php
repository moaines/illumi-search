<?php

namespace Moaines\LaravelFts;

use Illuminate\Database\Eloquent\Model;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Jobs\DeleteIndexJob;
use Moaines\LaravelFts\Jobs\IndexModelJob;

trait Searchable
{
    public static function bootSearchable(): void
    {
        $indexing = config('fts.indexing', 'queue');

        if ($indexing === 'manual') {
            return;
        }

        $queue = config('fts.queue_connection');

        static::saved(function (Model $model) use ($indexing, $queue) {
            if ($model->shouldFtsSync()) {
                if ($indexing === 'queue') {
                    dispatch(new IndexModelJob($model::class, $model->getKey()))->onConnection($queue);
                } else {
                    static::syncToFts($model);
                }
            }
        });

        static::deleted(function (Model $model) use ($indexing, $queue) {
            if ($indexing === 'queue') {
                dispatch(new DeleteIndexJob($model::class, $model->getKey()))->onConnection($queue);
            } else {
                $engine = app(FtsEngine::class);
                $engine->delete($model::class, $model->getKey());
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) use ($indexing, $queue) {
                if ($model->shouldFtsSync()) {
                    if ($indexing === 'queue') {
                        dispatch(new IndexModelJob($model::class, $model->getKey()))->onConnection($queue);
                    } else {
                        static::syncToFts($model);
                    }
                }
            });
        }
    }

    public function shouldFtsSync(): bool
    {
        return $this->ftsSyncOnSave ?? true;
    }

    public function ftsColumnName(string $column): string
    {
        return str_replace(['.', '->', '-'], '_', $column);
    }

    public function toFtsDocument(): array
    {
        $columns = $this->normalizeFtsSearchable();
        $document = [];

        foreach ($columns as $column => $config) {
            $safeName = $this->ftsColumnName($column);
            $document[$safeName] = $this->resolveFtsValue($column);
        }

        return $document;
    }

    public function resolveFtsValue(string $column): string
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

            if ($related instanceof \Illuminate\Support\Collection || $related instanceof \Illuminate\Database\Eloquent\Collection) {
                $max = config('fts.max_related_values', 100);

                return $related->pluck($last)->filter()->take($max)->implode(' ');
            }

            return (string) ($related->$last ?? $related->getAttribute($last) ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function ftsRelationsForRebuild(): array
    {
        $relations = [];
        foreach ($this->getFtsSearchableColumns() as $key => $config) {
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

    public function validateFtsSearchable(): array
    {
        $warnings = [];

        foreach ($this->getFtsSearchableColumns() as $key => $config) {
            $colName = is_string($config) ? $config : $key;

            if (! str_contains($colName, '.')) {
                continue;
            }

            $relName = explode('.', $colName)[0];

            if (! method_exists($this, $relName)) {
                $warnings[] = class_basename(static::class)
                    . "::ftsSearchable: relation '{$relName}' introuvable pour '{$colName}'";
            }
        }

        return $warnings;
    }

    public function ftsUrl(): ?string
    {
        return null;
    }

    public function ftsCategory(): ?string
    {
        if (isset($this->ftsCategory)) {
            return $this->ftsCategory;
        }

        return \Illuminate\Support\Str::plural(class_basename(static::class));
    }

    public function getFtsSearchableColumns(): array
    {
        return $this->ftsSearchable ?? [];
    }

    public function ftsTextProcessor(): ?string
    {
        return null;
    }

    public static function resolveProcessorFor(Model $model, TextProcessor $global): TextProcessor
    {
        $customClass = $model->ftsTextProcessor();

        if ($customClass !== null && class_exists($customClass)) {
            return app($customClass);
        }

        return $global;
    }

    /**
     * Normalize $ftsSearchable to a consistent ['column' => [...config...]] format.
     * Accepts:
     *   ['title' => ['weight' => 3]]   → explicit config
     *   ['author' => true]             → shorthand (default weight)
     *   ['author']                     → minimal (no weight, no config)
     */
    public function normalizeFtsSearchable(): array
    {
        $raw = $this->getFtsSearchableColumns();

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
     * Process a model's FTS document through the appropriate TextProcessor.
     * Respects per-column locale configuration.
     */
    public static function processDocument(Model $model, TextProcessor $global): array
    {
        $columns = $model->normalizeFtsSearchable();
        $doc = $model->toFtsDocument();
        $processor = static::resolveProcessorFor($model, $global);
        $processed = [];

        foreach ($columns as $column => $config) {
            $safeName = $model->ftsColumnName($column);
            $value = $doc[$safeName] ?? '';
            $locale = $config['locale'] ?? app()->getLocale() ?? 'en';
            $processed[$column] = $processor->process((string) $value, $locale);
        }

        return $processed;
    }

    public static function syncToFts(Model $model): void
    {
        $engine = app(FtsEngine::class);
        $global = app(TextProcessor::class);
        $processed = static::processDocument($model, $global);

        $engine->upsert($model::class, $model->getKey(), $processed);
    }
}
