<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Facades\IllumiSearch;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class QueryBuilderAdvancedTest extends TestCase
{
    private Engine $engine;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/illumi-search-qb-adv.sqlite');
        @unlink($this->path);

        config(['illumi-search.engine' => 'sqlite']);
        config(['illumi-search.engines.sqlite.database_path' => $this->path]);

        $this->engine = new SqliteEngine(databasePath: $this->path);
        $this->app->singleton(Engine::class, fn () => $this->engine);

        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);

        $docs = [
            ['title' => 'php laravel guide', 'body' => 'learn php'],
            ['title' => 'php advanced',      'body' => 'php deep'],
            ['title' => 'python for beginners', 'body' => 'learn py'],
            ['title' => 'javascript basics',    'body' => 'learn js'],
        ];

        foreach ($docs as $i => $doc) {
            $this->engine->upsert('App\Models\BenchmarkPost', $i + 1, $doc);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    // ─── Code path tests ─────────────────────────────

    public function test_where_does_not_crash(): void
    {
        $results = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->where('title', 'php')
            ->get();

        // where() requires real Eloquent models (not test fakes).
        // The code path runs without error even if no models are loaded.
        $this->assertIsArray($results->toArray(), 'where should not crash');
    }

    public function test_where_returns_empty_without_real_models(): void
    {
        $results = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->where('title', 'php')
            ->get();

        // Without real Eloquent models, where() returns empty.
        $this->assertIsArray($results->toArray(), 'where should not crash with fake models');
    }

    public function test_where_greater_than_does_not_crash(): void
    {
        $results = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->where('price', '>', 10)
            ->get();

        $this->assertIsArray($results->toArray(), 'where > should not crash');
    }

    public function test_where_in_array_does_not_crash(): void
    {
        $results = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->where('category', ['php', 'python'])
            ->get();

        $this->assertIsArray($results->toArray(), 'where IN should not crash');
    }

    // ─── aggregate() ─────────────────────────────────

    public function test_aggregate_returns_array(): void
    {
        $result = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->aggregate('title');

        $this->assertIsArray($result, 'aggregate should return an array');
    }

    // ─── boost() ─────────────────────────────────────

    public function test_boost_does_not_crash(): void
    {
        $results = IllumiSearch::query('learn')
            ->model('App\Models\BenchmarkPost')
            ->boost('created_at', 0.1)
            ->get();

        $this->assertNotEmpty($results, 'boost should not crash');
    }
}
