<?php

namespace Moaines\IllumiSearch\Tests\Unit\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Jobs\IndexModelJob;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;
use Moaines\IllumiSearch\Tests\TestCase;

class IndexModelJobTest extends TestCase
{
    private \Moaines\IllumiSearch\Contracts\Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(\Moaines\IllumiSearch\Contracts\Engine::class);
    }

    public function test_handle_upserts_model_to_engine(): void
    {
        $post = Post::withoutEvents(fn () => Post::forceCreate(['title' => 'test', 'body' => 'index me']));

        $engine = $this->createMock(Engine::class);
        $engine->expects($this->once())->method('upsert');

        $job = new IndexModelJob(Post::class, $post->id);
        $job->handle($engine, app(TextProcessor::class));
    }

    public function test_handle_skips_when_model_not_found(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects($this->never())->method('upsert');

        $job = new IndexModelJob(Post::class, 999);
        $job->handle($engine, app(TextProcessor::class));
    }
}
