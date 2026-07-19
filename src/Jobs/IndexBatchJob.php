<?php

namespace Moaines\IllumiSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Throwable;

class IndexBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(
        private readonly string $modelClass,
        private readonly int $lastId,
        private readonly int $limit,
    ) {}

    public function handle(FtsEngine $engine, TextProcessor $global): void
    {
        $model = new $this->modelClass;
        $keyName = $model->getKeyName();

        $records = $this->modelClass::query()
            ->orderBy($keyName)
            ->where($keyName, '>', $this->lastId)
            ->limit($this->limit)
            ->get();

        if ($records->isEmpty()) {
            return;
        }

        $documents = [];

        foreach ($records as $record) {
            $documents[] = [
                'model_id' => $record->getKey(),
                'document' => $record->processDocument($record, $global),
            ];
        }

        $engine->insertBatch($this->modelClass, $documents);
    }

    public function failed(?Throwable $e): void
    {
        report($e);
    }
}
