<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Http\Controllers\SearchApiController;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class ApiSearchTest extends TestCase
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

        config(['illumi-search.api.enabled' => true]);

        $this->registerApiRoutes();
    }

    protected function registerApiRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api/search')
            ->group(function () {
                Route::get('/', SearchApiController::class);
            });
    }

    public function test_search_returns_results(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable(Post::class, ['title', 'body']);
        $engine->upsert(Post::class, 1, ['title' => 'laravel testing', 'body' => 'php unit']);

        $this->getJson('/api/search?q=laravel&models=' . urlencode(Post::class))
            ->assertOk()
            ->assertJsonStructure(['results', 'total', 'suggestions']);
    }

    public function test_search_requires_query(): void
    {
        $this->getJson('/api/search')
            ->assertStatus(422);
    }

    public function test_search_returns_empty_without_data(): void
    {
        $this->getJson('/api/search?q=laravel')
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    public function test_search_with_limit(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable(Post::class, ['title', 'body']);
        $engine->upsert(Post::class, 10, ['title' => 'php 8', 'body' => 'test']);
        $engine->upsert(Post::class, 11, ['title' => 'php 9', 'body' => 'test']);
        $engine->upsert(Post::class, 12, ['title' => 'php 10', 'body' => 'test']);

        $this->getJson('/api/search?q=php&limit=2&models=' . urlencode(Post::class))
            ->assertOk()
            ->assertJsonCount(2, 'results');
    }

    public function test_spellcheck_via_suggest(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $this->getJson('/api/search?q=laravell&suggest=1&models=' . urlencode(Post::class))
            ->assertOk()
            ->assertJsonStructure(['suggestions']);
    }
}
