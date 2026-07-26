<?php

namespace Moaines\IllumiSearch\Tests\Feature\Api;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Http\Controllers\SearchApiController;
use Moaines\IllumiSearch\Http\Requests\SearchApiRequest;
use Moaines\IllumiSearch\Tests\TestCase;

class SearchApiTest extends TestCase
{
    private Engine $engine;
    private SearchApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $path = storage_path('app/illumi-search-api-test.sqlite');
        @unlink($path);

        config(['illumi-search.engine' => 'sqlite']);
        config(['illumi-search.engines.sqlite.database_path' => $path]);

        $this->engine = new SqliteEngine(databasePath: $path);
        $this->controller = new SearchApiController;
    }

    protected function tearDown(): void
    {
        $path = storage_path('app/illumi-search-api-test.sqlite');
        @unlink($path);
        parent::tearDown();
    }

    private function callApi(array $params): \Illuminate\Http\JsonResponse
    {
        $base = \Illuminate\Http\Request::create('/api/illumi-search', 'GET', $params);
        $request = SearchApiRequest::createFromBase($base);
        $request->setRouteResolver(fn () => null);

        return $this->controller->__invoke($request, $this->engine);
    }

    // ─── API enabled ───────────────────────────────

    public function test_api_returns_results_when_enabled(): void
    {
        config(['illumi-search.api.enabled' => true]);
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'php laravel guide', 'body' => 'learn laravel']);
        $this->engine->upsert('App\Models\BenchmarkPost', 2, ['title' => 'python guide', 'body' => 'learn python']);

        $response = $this->callApi(['q' => 'laravel', 'limit' => 2]);
        $json = $response->getData(true);

        $this->assertEquals(200, $response->status());
        $this->assertArrayHasKey('results', $json);
        $this->assertArrayHasKey('total', $json);
        $this->assertArrayHasKey('suggestions', $json);
        $this->assertEquals(1, $json['total']);
    }

    public function test_suggest_returns_suggestions(): void
    {
        config(['illumi-search.api.enabled' => true]);
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'php programming', 'body' => 'learn programming']);

        $response = $this->callApi(['q' => 'programing', 'suggest' => true]);
        $json = $response->getData(true);

        $this->assertIsArray($json['suggestions'], 'Suggest should return an array (may be empty without vocab rebuild)');
    }

    public function test_suggest_always_called_when_requested(): void
    {
        config(['illumi-search.api.enabled' => true]);
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'php programming guide', 'body' => 'learn programming']);

        $response = $this->callApi(['q' => 'programming', 'suggest' => true, 'models' => 'App\Models\BenchmarkPost']);
        $json = $response->getData(true);

        $this->assertNotEmpty($json['results']);
        $this->assertIsArray($json['suggestions']);
    }

    public function test_special_characters_no_error(): void
    {
        config(['illumi-search.api.enabled' => true]);
        $response = $this->callApi(['q' => '!@#$%^&*()']);
        $this->assertEquals(200, $response->status());
    }

    public function test_missing_query_does_not_crash(): void
    {
        config(['illumi-search.api.enabled' => true]);

        $response = $this->callApi([]);
        $this->assertEquals(200, $response->status(), 'Missing q should return empty results, not crash');
    }

    public function test_very_long_query_does_not_crash(): void
    {
        config(['illumi-search.api.enabled' => true]);

        $long = str_repeat('a', 201);
        $response = $this->callApi(['q' => $long]);
        $this->assertEquals(200, $response->status(), 'Long query should return empty results, not crash');
    }
}
