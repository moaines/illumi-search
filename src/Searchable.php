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
        $columns = $this->getFtsSearchableColumns();
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

    public function ftsIcon(): ?string
    {
        return $this->ftsIcon ?? null;
    }

    public function ftsCategory(): ?string
    {
        return $this->ftsCategory ?? null;
    }

    public function getFtsSearchableColumns(): array
    {
        return $this->ftsSearchable ?? [];
    }

    /**
     * Override to use a custom TextProcessor for this model.
     * Return a class name implementing TextProcessor, or null for the global one.
     */
    public function ftsTextProcessor(): ?string
    {
        return null;
    }

    /**
     * Resolve the TextProcessor for a given model (supports custom per-model).
     */
    public static function resolveProcessorFor(Model $model, TextProcessor $global): TextProcessor
    {
        $customClass = $model->ftsTextProcessor();

        if ($customClass !== null && class_exists($customClass)) {
            return app($customClass);
        }

        return $global;
    }

    /**
     * Process a model's FTS document through the appropriate TextProcessor.
     * Shared between sync, rebuild, batch jobs, and model jobs.
     *
     * @return array<string, string>
     */
    public static function processDocument(Model $model, TextProcessor $global): array
    {
        $doc = $model->toFtsDocument();
        $processor = static::resolveProcessorFor($model, $global);
        $processed = [];

        foreach ($doc as $key => $value) {
            $processed[$key] = $processor->process((string) $value);
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
