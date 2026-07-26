<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Contracts\Engine;

class DataScaler
{
    private const SCALE_BATCH = 100;

    public function __construct(
        private readonly Engine $engine,
        private readonly string $modelClass,
        private readonly array $columns,
    ) {}

    public function scaleTo(int $targetCount, string $table): void
    {
        $existing = DB::connection()->table($table)->count();
        if ($existing >= $targetCount) {
            return;
        }

        $original = DB::connection()->table($table)
            ->where('model_type', $this->modelClass)
            ->first();

        if (! $original) {
            return;
        }

        $copyCount = $targetCount - $existing;
        $batches = array_chunk(range(1, $copyCount), self::SCALE_BATCH);

        foreach ($batches as $batch) {
            $this->engine->setRebuilding(true);
            $documents = [];
            foreach ($batch as $i) {
                $docId = $existing + $i;
                $suffix = " [{$docId}]";
                $documents[] = [
                    'model_id' => $docId,
                    'document' => [
                        'title' => ($original->title ?? 'title') . $suffix,
                        'body' => ($original->body ?? 'body') . $suffix,
                    ],
                ];
            }
            $this->engine->insertBatch($this->modelClass, $documents);
            $this->engine->setRebuilding(false);
        }
    }

    public function clean(int $originalCount, string $table): void
    {
        $this->engine->setRebuilding(true);
        DB::connection()->table($table)
            ->where('model_type', $this->modelClass)
            ->where('model_id', '>', $originalCount)
            ->delete();
        $this->engine->setRebuilding(false);
    }
}
