<?php

namespace Moaines\LaravelFts\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\FtsIndexManager;
use Moaines\LaravelFts\Jobs\IndexBatchJob;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Tests\TestCase;

class LazyIndexingTest extends TestCase
{
    private FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(FtsEngine::class);
    }

    private function createPost(int $id, string $title, string $body = ''): void
    {
        Post::withoutEvents(function () use ($id, $title, $body) {
            Post::forceCreate([
                'id' => $id,
                'title' => $title,
                'body' => $body,
            ]);
        });
    }

    public function test_rebuild_with_small_dataset_indexes_all_synchronously(): void
    {
        $this->createPost(1, 'post a', 'content');
        $this->createPost(2, 'post b', 'content');

        Bus::fake();

        $manager = $this->app->make(FtsIndexManager::class);
        $results = $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 100, // larger than dataset → all sync
        );

        $this->assertCount(1, $results);
        $this->assertEquals('indexed', $results[0]['status']);
        $this->assertEquals(2, $results[0]['records']);
        $this->assertEquals(0, $results[0]['queued']);

        Bus::assertNothingDispatched();
    }

    public function test_rebuild_with_batch_smaller_than_dataset_dispatches_queue_jobs(): void
    {
        $this->createPost(1, 'post a', 'content');
        $this->createPost(2, 'post b', 'content');
        $this->createPost(3, 'post c', 'content');
        $this->createPost(4, 'post d', 'content');
        $this->createPost(5, 'post e', 'content');

        Bus::fake();

        $manager = $this->app->make(FtsIndexManager::class);
        $results = $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 2, // only 2 sync, rest queued
        );

        $this->assertCount(1, $results);
        $this->assertEquals('indexed', $results[0]['status']);
        $this->assertEquals(2, $results[0]['records']); // 2 synced
        $this->assertEquals(3, $results[0]['queued']);  // 3 queued
        $this->assertEquals(5, $results[0]['total']);

        Bus::assertDispatchedTimes(IndexBatchJob::class, 1); // 1 batch for the remaining 3 records
    }

    public function test_rebuild_with_batch_zero_always_indexes_all_synchronously(): void
    {
        $this->createPost(1, 'test', 'content');
        $this->createPost(2, 'test', 'content');

        Bus::fake();

        $manager = $this->app->make(FtsIndexManager::class);
        $results = $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 0, // 0 = always sync all
        );

        $this->assertEquals(2, $results[0]['records']);
        $this->assertEquals(0, $results[0]['queued']);

        Bus::assertNothingDispatched();
    }

    public function test_rebuild_with_batch_equal_to_dataset_size_syncs_all(): void
    {
        $this->createPost(1, 'test', 'content');
        $this->createPost(2, 'test', 'content');

        Bus::fake();

        $manager = $this->app->make(FtsIndexManager::class);
        $results = $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 2, // equal to dataset → no queue needed
        );

        $this->assertEquals(2, $results[0]['records']);
        $this->assertEquals(0, $results[0]['queued']);

        Bus::assertNothingDispatched();
    }

    public function test_rebuild_dispatches_multiple_jobs_for_large_remaining_dataset(): void
    {
        for ($i = 1; $i <= 350; $i++) {
            $this->createPost($i, "post {$i}", 'content');
        }

        Bus::fake();

        $manager = $this->app->make(FtsIndexManager::class);
        $results = $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 50, // 50 synced, 300 remaining
        );

        $this->assertEquals(50, $results[0]['records']);
        $this->assertEquals(300, $results[0]['queued']);

        // 300 / 100 per job → 3 jobs
        Bus::assertDispatchedTimes(IndexBatchJob::class, 3);
    }

    public function test_indexed_records_are_searchable(): void
    {
        $this->createPost(1, 'hello world', 'test content');
        $this->createPost(2, 'foo bar', 'lorem ipsum');

        $manager = $this->app->make(FtsIndexManager::class);
        $manager->rebuild(
            modelClasses: [Post::class],
            batchSize: 1, // batch of 1
        );

        // The first record was synced synchronously and should be searchable
        $searchResults = $this->engine->search('hello', [Post::class], 10);
        $this->assertGreaterThanOrEqual(1, count($searchResults));
    }
}
