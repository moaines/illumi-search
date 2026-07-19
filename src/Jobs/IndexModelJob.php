<?php

namespace Moaines\IllumiSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
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

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->modelId))->shared()->releaseAfter(10)];
    }

    public function handle(Engine $engine, TextProcessor $global): void
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
