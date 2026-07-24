<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class SyncCommandTest extends TestCase
{
    private const MODEL_CLASS = Post::class;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        app(Engine::class)->createTable(self::MODEL_CLASS, ['title', 'body']);
    }

    public function test_sync_all_records(): void
    {
        Post::forceCreate(['id' => 1, 'title' => 'post one', 'body' => 'content']);
        Post::forceCreate(['id' => 2, 'title' => 'post two', 'body' => 'content']);

        $this->artisan('illumi-search:sync')
            ->assertSuccessful()
            ->expectsOutput('Sync complete.');
    }

    public function test_sync_with_since_only_processes_recent_records(): void
    {
        $oldDate = Carbon::now()->subDays(5);
        $recentDate = Carbon::now()->subHour();

        Post::forceCreate([
            'id' => 1, 'title' => 'old post', 'body' => 'old content',
            'updated_at' => $oldDate, 'created_at' => $oldDate,
        ]);

        Post::forceCreate([
            'id' => 2, 'title' => 'recent post', 'body' => 'recent content',
            'updated_at' => $recentDate, 'created_at' => $recentDate,
        ]);

        $since = Carbon::now()->subDays(1)->toIso8601String();

        $this->artisan("illumi-search:sync --since=\"{$since}\"")
            ->assertSuccessful()
            ->expectsOutput('Sync complete.');
    }
}
