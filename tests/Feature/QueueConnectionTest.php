<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Jobs\IndexModelJob;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class QueueConnectionTest extends TestCase
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

    public function test_queue_connection_is_read_and_applied(): void
    {
        config(['illumi-search.indexing.mode' => 'queue']);
        config(['illumi-search.queue_connection' => 'sync']);
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'go']);

        Bus::assertDispatched(IndexModelJob::class);
    }

    public function test_null_connection_uses_default_queue(): void
    {
        config(['illumi-search.indexing.mode' => 'queue']);
        config(['illumi-search.queue_connection' => null]);
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'go']);

        Bus::assertDispatched(IndexModelJob::class);
    }
}
