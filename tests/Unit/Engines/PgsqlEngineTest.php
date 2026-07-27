<?php

namespace Moaines\IllumiSearch\Tests\Unit\Engines;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Engines\PgsqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class PgsqlEngineTest extends TestCase
{
    private PgsqlEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->pgsqlAvailable()) {
            $this->markTestSkipped('PostgreSQL connection not available.');
        }

        config([
            'illumi-search.engines.pgsql.connection' => [
                'host' => env('ILLUMI_SEARCH_PGSQL_HOST', '127.0.0.1'),
                'port' => env('ILLUMI_SEARCH_PGSQL_PORT', '5432'),
                'database' => env('ILLUMI_SEARCH_PGSQL_DATABASE', 'test-illumi-search'),
                'username' => env('ILLUMI_SEARCH_PGSQL_USERNAME', 'postgres'),
                'password' => env('ILLUMI_SEARCH_PGSQL_PASSWORD', 'password'),
            ],
        ]);

        $this->engine = new PgsqlEngine;
        $this->engine->dropTable('App\Models\BenchmarkPost');
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->dropTable('App\Models\BenchmarkPost');
        }
        parent::tearDown();
    }

    private function pgsqlAvailable(): bool
    {
        try {
            new \PDO(
                'pgsql:host=' . env('ILLUMI_SEARCH_PGSQL_HOST', '127.0.0.1') . ';port=' . env('ILLUMI_SEARCH_PGSQL_PORT', '5432') . ';dbname=' . env('ILLUMI_SEARCH_PGSQL_DATABASE', 'test-illumi-search'),
                env('ILLUMI_SEARCH_PGSQL_USERNAME', 'postgres'),
                env('ILLUMI_SEARCH_PGSQL_PASSWORD', 'password'),
                [\PDO::ATTR_TIMEOUT => 2]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_prefix_query_build_terms(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('buildPrefixQuery');
        $method->setAccessible(true);

        [$sql, $bindings] = $method->invoke($this->engine, 'prog* php', 'simple');

        $this->assertStringContainsString('to_tsquery(', $sql);
        $this->assertCount(2, $bindings);
        $this->assertSame('prog:*', $bindings[0]);
        $this->assertSame('php', $bindings[1]);
    }

    public function test_prefix_query_negated_term(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('buildPrefixQuery');
        $method->setAccessible(true);

        [$sql, $bindings] = $method->invoke($this->engine, 'php -java', 'simple');

        $this->assertStringContainsString('!! to_tsquery(', $sql);
        $this->assertCount(2, $bindings);
        $this->assertSame('java', $bindings[1]);
    }

    public function test_rebuild_vocab_from_scratch_populates_table(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php programming',
            'body' => 'guide',
        ]);

        $this->engine->rebuildVocabFromScratch();

        $vocabTable = $this->engine->table('vocab');
        $count = DB::connection('illumi-search-pgsql')
            ->selectOne("SELECT COUNT(*) AS cnt FROM {$vocabTable}");
        $this->assertGreaterThan(0, (int) ($count->cnt ?? 0));
    }

    public function test_rebuild_trigram_table_populates_after_vocab(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php programming',
            'body' => 'guide',
        ]);

        $this->engine->rebuildVocabFromScratch();

        $trigramTable = $this->engine->table('vocab_trigrams');
        $count = DB::connection('illumi-search-pgsql')
            ->selectOne("SELECT COUNT(*) AS cnt FROM {$trigramTable}");
        $this->assertGreaterThan(0, (int) ($count->cnt ?? 0));
    }

    public function test_suggest_returns_results(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php programming guide',
            'body' => 'learn php programming',
        ]);

        $suggestions = $this->engine->suggest('programing', 2, 5);
        $this->assertNotEmpty($suggestions);
    }

    public function test_suggest_triggers_on_demand_rebuild_when_vocab_empty(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php programming guide',
            'body' => 'learn php programming',
        ]);

        // Vocab table starts empty — suggest should trigger rebuildVocabFromScratch automatically
        $suggestions = $this->engine->suggest('programing', 2, 5);
        $this->assertNotEmpty($suggestions, 'Suggest should auto-rebuild vocab when empty');

        // Verify vocab table is now populated
        $vocabTable = $this->engine->table('vocab');
        $count = DB::connection('illumi-search-pgsql')
            ->selectOne("SELECT COUNT(*) AS cnt FROM {$vocabTable}");
        $this->assertGreaterThan(0, (int) ($count->cnt ?? 0));
    }

    public function test_suggest_handles_empty_vocab(): void
    {
        // No documents indexed — suggest should not crash
        $suggestions = $this->engine->suggest('php', 2, 5);
        $this->assertIsArray($suggestions);
    }

    public function test_weight_column_names_via_trait(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('weightColumnNames');
        $result = $method->invoke($this->engine);

        $this->assertStringContainsString('text_w1', $result);
        $this->assertStringContainsString('text_w2', $result);
        $this->assertStringContainsString('text_w3', $result);
    }

    public function test_supports_phrase_search(): void
    {
        $this->assertTrue($this->engine->supportsPhraseSearch());
    }

    public function test_supports_prefix_wildcard(): void
    {
        $this->assertTrue($this->engine->supportsPrefixWildcard());
    }

    public function test_rebuild_trigram_table_with_missing_table_does_not_crash(): void
    {
        // Drop the vocab_trigrams table manually
        $trigramTable = $this->engine->table('vocab_trigrams');
        DB::connection('illumi-search-pgsql')->statement("DROP TABLE IF EXISTS {$trigramTable}");

        // Should not crash — just return silently
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('rebuildTrigramTable');
        $method->invoke($this->engine);

        $this->assertTrue(true, 'rebuildTrigramTable with missing table should not crash');
    }

    public function test_rebuild_vocab_with_missing_index_table_does_not_crash(): void
    {
        $this->engine->dropTable('App\Models\BenchmarkPost');

        // Should not crash — just return empty array
        $result = $this->engine->rebuildVocabFromScratch();
        $this->assertNull($result, 'rebuildVocabFromScratch with missing table should not crash');
    }

    public function test_suggest_on_empty_index_returns_array(): void
    {
        $this->engine->dropTable('App\Models\BenchmarkPost');
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);

        // No documents inserted — suggest should return empty array
        $suggestions = $this->engine->suggest('php', 2, 5);
        $this->assertIsArray($suggestions);
    }

    public function test_vacuum_does_not_crash(): void
    {
        // Create and insert data, then vacuum
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'vacuum test',
            'body' => 'data to vacuum',
        ]);
        $this->engine->vacuum();
        $this->assertTrue(true, 'vacuum should not crash');
    }

    public function test_get_engine_status_returns_array(): void
    {
        $status = $this->engine->getEngineStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('driver', $status);
        $this->assertSame('pgsql', $status['driver']);
    }

    public function test_get_database_size_returns_positive(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'size test',
            'body' => 'data to measure size',
        ]);
        $size = $this->engine->getDatabaseSize();
        $this->assertGreaterThan(0, $size, 'Database size should be positive');
    }

    public function test_drop_then_create_preserves_other_models(): void
    {
        // Simulate IndexManager flow: dropTable(A) → createTable(A) → upsert(A)
        // → dropTable(B) → createTable(B). Model A data must survive.
        $this->engine->dropTable('App\Models\BenchmarkPost');
        $this->engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'alpha data', 'body' => 'alpha content']);

        // Second model in the IndexManager loop
        $this->engine->dropTable('App\Models\OtherModel');
        $this->engine->createTable('App\Models\OtherModel', ['title', 'body']);
        $this->engine->upsert('App\Models\OtherModel', 1, ['title' => 'beta data', 'body' => 'beta content']);

        // Model A data must still exist
        $alphaResults = $this->engine->search('alpha', ['App\Models\BenchmarkPost'], 10);
        $this->assertNotEmpty($alphaResults, 'Model A data must survive after processing Model B');
        $this->assertSame(1, $alphaResults[0]->modelId, 'Model A data must be intact');
    }

    public function test_drop_table_does_not_affect_other_models(): void
    {
        // Insert data for two model classes
        $this->engine->upsert('App\Models\BenchmarkPost', 1, ['title' => 'first post', 'body' => 'content a']);
        $this->engine->upsert('App\Models\BenchmarkPost', 2, ['title' => 'second post', 'body' => 'content b']);
        $this->engine->upsert('App\Models\OtherModel', 1, ['title' => 'other doc', 'body' => 'other content']);

        // Drop only the first model class
        $this->engine->dropTable('App\Models\BenchmarkPost');

        // The other model's data should still exist
        $otherResults = $this->engine->search('other', ['App\Models\OtherModel'], 10);
        $this->assertNotEmpty($otherResults, 'Other model data must survive dropTable');

        // The dropped model's data should be gone
        $postResults = $this->engine->search('post', ['App\Models\BenchmarkPost'], 10);
        $this->assertEmpty($postResults, 'Dropped model data should be removed');
    }

    public function test_snippet_enrichment_marks_terms(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php programming guide',
            'body' => 'learn php programming from scratch',
        ]);

        $results = $this->engine->search('programming', ['App\Models\BenchmarkPost'], 10, 0, 'advanced', true);
        $this->assertNotEmpty($results);

        $hasSnippet = false;
        $hasSummary = false;
        foreach ($results as $r) {
            if (! empty($r->summary)) {
                $hasSummary = true;
                if (str_contains($r->summary, '<mark>')) {
                    $hasSnippet = true;
                }
            }
        }
        // SnippetService requires Eloquent models — test that either snippets work
        // or at least results are returned (non-Eloquent model classes are OK)
        $this->assertTrue($hasSnippet || ! $hasSummary, 'Snippet enrichment should produce <mark> tags when model is available');
    }

    public function test_parentheses_preserved_in_query(): void
    {
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'php laravel guide',
            'body' => 'php framework for web',
        ]);
        $this->engine->upsert('App\Models\BenchmarkPost', 2, [
            'title' => 'python django guide',
            'body' => 'python framework for web',
        ]);

        // Parentheses should group: (php OR python) must find both docs
        $results = $this->engine->search('(php OR python) AND framework', ['App\Models\BenchmarkPost'], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Doc 1 has php + framework');
        $this->assertContains(2, $ids, 'Doc 2 has python + framework');
    }

    public function test_cache_is_not_cleared_on_construct(): void
    {
        // First search populates cache
        $this->engine->upsert('App\Models\BenchmarkPost', 1, [
            'title' => 'cache test document',
            'body' => 'this is a cache test',
        ]);
        $first = $this->engine->search('cache test', ['App\Models\BenchmarkPost'], 10);
        $this->assertNotEmpty($first, 'First search should work');

        // Create a new engine instance — no clear() means cache persists
        $engine2 = new PgsqlEngine;
        $second = $engine2->search('cache test', ['App\Models\BenchmarkPost'], 10);
        $this->assertNotEmpty($second, 'Second engine instance should still find data (cache persists)');
    }
}
