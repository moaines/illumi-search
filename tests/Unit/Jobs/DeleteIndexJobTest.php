<?php

namespace Moaines\IllumiSearch\Tests\Unit\Jobs;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Jobs\DeleteIndexJob;
use Moaines\IllumiSearch\Tests\TestCase;

class DeleteIndexJobTest extends TestCase
{
    public function test_handle_calls_engine_delete(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects($this->once())
            ->method('delete')
            ->with('App\Models\Post', 42);

        $job = new DeleteIndexJob('App\Models\Post', 42);
        $job->handle($engine);
    }
}
