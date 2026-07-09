<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Facades\Fts;
use Moaines\LaravelFts\FtsIndexManager;
use Moaines\LaravelFts\Jobs\IndexModelJob;
use Moaines\LaravelFts\Jobs\DeleteIndexJob;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Tests\TestCase;

class SearchableTraitTest extends TestCase
{
    private \Moaines\LaravelFts\Contracts\FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(\Moaines\LaravelFts\Contracts\FtsEngine::class);
    }

    private function createPostIndex(): void
    {
        $this->engine->createTable(Post::class, ['title', 'body']);
    }

    private function createPostSafely(array $data): Post
    {
        return Post::withoutEvents(fn () => Post::forceCreate($data));
    }

    public function test_saved_dispatches_job_in_queue_mode(): void
    {
        config(['fts.indexing' => 'queue']);
        $this->createPostIndex();
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertDispatched(IndexModelJob::class);
    }

    public function test_saved_indexes_directly_in_sync_mode(): void
    {
        config(['fts.indexing' => 'sync']);
        $this->createPostIndex();

        $post = Post::forceCreate(['title' => 'test index', 'body' => 'content']);

        $results = $this->engine->search('test index', [Post::class], 10, withSnippets: false);

        $this->assertCount(1, $results);
    }

    public function test_deleted_dispatches_delete_job(): void
    {
        config(['fts.indexing' => 'queue']);

        $post = $this->createPostSafely(['title' => 'test', 'body' => 'content']);

        Bus::fake();

        $post->delete();

        Bus::assertDispatched(DeleteIndexJob::class);
    }

    public function test_manual_mode_does_nothing(): void
    {
        config(['fts.indexing' => 'manual']);
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertNotDispatched(IndexModelJob::class);
    }
}
