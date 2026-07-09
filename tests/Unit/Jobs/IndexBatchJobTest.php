<?php

namespace Moaines\LaravelFts\Tests\Unit\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Jobs\IndexBatchJob;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Tests\TestCase;

class IndexBatchJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function test_handle_calls_insert_batch(): void
    {
        Post::withoutEvents(function () {
            Post::forceCreate(['title' => 'test', 'body' => 'batch 1']);
            Post::forceCreate(['title' => 'test', 'body' => 'batch 2']);
        });

        $engine = $this->createMock(FtsEngine::class);
        $engine->expects($this->once())->method('insertBatch');

        $job = new IndexBatchJob(Post::class, 0, 10);
        $job->handle($engine, app(TextProcessor::class));
    }

    public function test_handle_skips_when_no_records(): void
    {
        $engine = $this->createMock(FtsEngine::class);
        $engine->expects($this->never())->method('insertBatch');

        $job = new IndexBatchJob(Post::class, 999, 10);
        $job->handle($engine, app(TextProcessor::class));
    }
}
