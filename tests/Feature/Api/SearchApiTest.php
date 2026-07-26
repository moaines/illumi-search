<?php

namespace Moaines\IllumiSearch\Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Http\Controllers\SearchApiController;
use Moaines\IllumiSearch\Tests\TestCase;

class SearchApiTest extends TestCase
{
    private Engine $engine;

    private function jsonGet(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->get($uri, ['Accept' => 'application/json']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['illumi-search.api.enabled' => true]);
        config(['illumi-search.engine' => 'sqlite']);

        Route::get('/api/search', SearchApiController::class);

        $path = storage_path('app/illumi-search-api-test.sqlite');
        @unlink($path);
        $this->engine = app(Engine::class);
    }

    protected function tearDown(): void
    {
        $path = storage_path('app/illumi-search-api-test.sqlite');
        @unlink($path);
        parent::tearDown();
    }

    public function test_simple_search_returns_json(): void
    {
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'php laravel guide', 'body' => 'learn laravel']);
        $this->engine->upsert('App\Models\BenchmarkPost', 2, ['title' => 'python guide', 'body' => 'learn python']);

        $this->jsonGet('/api/search?q=laravel')
            ->assertOk()
            ->assertJsonStructure(['results', 'total', 'suggestions'])
            ->assertJsonFragment(['total' => 1]);
    }

    public function test_search_returns_422_without_query(): void
    {
        $this->jsonGet('/api/search')
            ->assertStatus(422);
    }

    public function test_search_returns_422_with_empty_query(): void
    {
        $this->jsonGet('/api/search?q=')
            ->assertStatus(422);
    }

    public function test_search_returns_invalid_suggest_value(): void
    {
        $this->jsonGet('/api/search?q=test&suggest=notabool')
            ->assertStatus(422);
    }

    public function test_search_returns_422_with_long_query(): void
    {
        $long = str_repeat('a', 201);
        $this->jsonGet('/api/search?q=' . $long)
            ->assertStatus(422);
    }

    public function test_suggest_returns_suggestions(): void
    {
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'php programming', 'body' => 'learn programming']);

        $response = $this->jsonGet('/api/search?q=programing&suggest=1');
        $response->assertOk();

        $json = $response->json();
        $this->assertNotEmpty($json['suggestions']);
    }

    public function test_special_characters_no_error(): void
    {
        $this->jsonGet('/api/search?q=' . rawurlencode('!@#$%^&*()'))
            ->assertOk();
    }
}
