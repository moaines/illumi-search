<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;
use Moaines\IllumiSearch\Tests\TestCase;

class SearchCommandTest extends TestCase
{
    public function test_search_returns_results(): void
    {
        $engine = $this->app->make(\Moaines\IllumiSearch\Contracts\Engine::class);
        $engine->createTable(Post::class, ['title', 'body']);
        $engine->upsert(Post::class, 1, ['title' => 'laravel testing', 'body' => 'php unit']);

        $this->artisan('illumi-search:search', [
            'query'   => 'laravel',
            '--models' => Post::class,
        ])->assertSuccessful();
    }

    public function test_search_json_format(): void
    {
        $engine = $this->app->make(\Moaines\IllumiSearch\Contracts\Engine::class);
        $engine->createTable(Post::class, ['title', 'body']);
        $engine->upsert(Post::class, 1, ['title' => 'laravel testing', 'body' => 'php unit']);

        $this->artisan('illumi-search:search', [
            'query'   => 'laravel',
            '--models' => Post::class,
            '--json'  => true,
        ])->assertSuccessful();
    }

    public function test_search_no_results_message(): void
    {
        $this->artisan('illumi-search:search', [
            'query' => 'zzznotfound',
        ])->assertSuccessful();
    }
}
