<?php

namespace Moaines\LaravelFts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Throwable;

class IndexModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 15];

    public function __construct(
        private readonly string $modelClass,
        private readonly int|string $modelId,
    ) {}

    public function handle(FtsEngine $engine, TextProcessor $global): void
    {
        $model = $this->modelClass::find($this->modelId);

        if ($model === null) {
            return;
        }

        $processed = $model->processDocument($model, $global);
        $engine->upsert($this->modelClass, $this->modelId, $processed);
    }

    public function failed(?Throwable $e): void
    {
        report($e);
    }
}
