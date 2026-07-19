<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    public function test_status_no_database(): void
    {
        $this->artisan('illumi-search:status')
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();
    }

    public function test_status_shows_index_stats(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $this->artisan('illumi-search:status')
            ->expectsOutputToContain('App\Models\Post')
            ->assertSuccessful();
    }
}
