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

        static::saved(function (Model $model) use ($indexing) {
            if ($model->shouldFtsSync()) {
                if ($indexing === 'queue') {
                    dispatch(new IndexModelJob($model::class, $model->getKey()));
                } else {
                    static::syncToFts($model);
                }
            }
        });

        static::deleted(function (Model $model) use ($indexing) {
            if ($indexing === 'queue') {
                dispatch(new DeleteIndexJob($model::class, $model->getKey()));
            } else {
                $engine = app(FtsEngine::class);
                $engine->delete($model::class, $model->getKey());
            }
        });
    }

    public function shouldFtsSync(): bool
    {
        return $this->ftsSyncOnSave ?? true;
    }

    public function toFtsDocument(): array
    {
        $columns = $this->normalizeFtsSearchable();
        $document = [];

        foreach ($columns as $column => $config) {
            $value = $this->{$column} ?? '';
            $document[$column] = $value;
        }

        return $document;
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
            $value = $doc[$column] ?? '';
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
