<?php

namespace Moaines\IllumiSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Moaines\IllumiSearch\Contracts\Engine;

class DeleteIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $modelClass,
        private readonly int|string $modelId,
    ) {}

    public function handle(Engine $engine): void
    {
        $engine->delete($this->modelClass, $this->modelId);
    }
}
