<?php

namespace Moaines\LaravelFts\Tests\Unit\Jobs;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Jobs\DeleteIndexJob;
use Moaines\LaravelFts\Tests\TestCase;

class DeleteIndexJobTest extends TestCase
{
    public function test_handle_calls_engine_delete(): void
    {
        $engine = $this->createMock(FtsEngine::class);
        $engine->expects($this->once())
            ->method('delete')
            ->with('App\Models\Post', 42);

        $job = new DeleteIndexJob('App\Models\Post', 42);
        $job->handle($engine);
    }
}
