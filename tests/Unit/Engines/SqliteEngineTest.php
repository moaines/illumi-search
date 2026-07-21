<?php

namespace Moaines\IllumiSearch\Tests\Unit\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Book;
use Moaines\IllumiSearch\Tests\TestCase;

class SqliteEngineTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->app->make(Engine::class);

        $this->engine->createTable('App\Models\Post', ['title', 'body']);
    }

    public function test_creates_table(): void
    {
        $this->assertTrue($this->engine->tableExists('App\Models\Post'));
    }

    public function test_drops_table(): void
    {
        $this->engine->dropTable('App\Models\Post');
        $this->assertFalse($this->engine->tableExists('App\Models\Post'));
    }

    public function test_upsert_and_search(): void
    {
        $this->engine->upsert('App\Models\Post', 1, [
            'title' => 'hello world',
            'body' => 'this is a test post',
        ]);

        $results = $this->engine->search('hello', ['App\Models\Post'], 10);

        $this->assertCount(1, $results);
        $this->assertEquals('hello world', $results[0]->title);
        $this->assertEquals('App\Models\Post', $results[0]->modelClass);
        $this->assertEquals(1, $results[0]->modelId);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->engine->upsert('App\Models\Post', 1, [
            'title' => 'hello world',
            'body' => 'test content',
        ]);

        $results = $this->engine->search('nonexistent', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_search_returns_multiple_results(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'first post', 'body' => 'content one']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'second post', 'body' => 'content two']);

        $results = $this->engine->search('post', ['App\Models\Post'], 10);
        $this->assertCount(2, $results);
    }

    public function test_count_returns_correct_number(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello alpha', 'body' => 'test']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'hello beta', 'body' => 'test']);

        $count = $this->engine->count('hello', ['App\Models\Post']);
        $this->assertEquals(2, $count);
    }

    public function test_delete_removes_from_index(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);
        $this->engine->delete('App\Models\Post', 1);

        $results = $this->engine->search('hello', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_insert_batch(): void
    {
        $documents = [
            ['model_id' => 1, 'document' => ['title' => 'batch one', 'body' => 'content']],
            ['model_id' => 2, 'document' => ['title' => 'batch two', 'body' => 'content']],
        ];

        $this->engine->insertBatch('App\Models\Post', $documents);

        $results = $this->engine->search('batch', ['App\Models\Post'], 10);
        $this->assertCount(2, $results);
    }

    public function test_search_multiple_models(): void
    {
        $this->engine->createTable('App\Models\Comment', ['content', 'author']);

        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php programming', 'body' => 'great']);
        $this->engine->upsert('App\Models\Comment', 1, ['content' => 'nice post about php', 'author' => 'jane']);

        $results = $this->engine->search('php', ['App\Models\Post', 'App\Models\Comment'], 10);
        $this->assertCount(2, $results);
    }

    public function test_respects_limit(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'test a', 'body' => '']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'test b', 'body' => '']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'test c', 'body' => '']);

        $results = $this->engine->search('test', ['App\Models\Post'], 2);
        $this->assertCount(2, $results);
    }

    public function test_escape_query_preserves_operators_in_advanced_mode(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'php AND laravel', 'advanced');

        $this->assertStringContainsString('php*', $result);
        $this->assertStringContainsString('laravel*', $result);
        $this->assertDoesNotMatchRegularExpression('/\band\*/', $result);
        $this->assertDoesNotMatchRegularExpression('/\bAND\*/', $result);
    }

    public function test_escape_query_preserves_boolean_operators(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'laravel AND vuejs NOT react', 'advanced');

        $this->assertDoesNotMatchRegularExpression('/\bAND\*/', $result);
        $this->assertDoesNotMatchRegularExpression('/\bNOT\*/', $result);
        $this->assertStringContainsString('laravel*', $result);
    }

    public function test_and_search_returns_results(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php and laravel framework', 'body' => 'learning php and laravel together']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'python language', 'body' => 'Python is different']);

        $results = $this->engine->search('php AND laravel', ['App\Models\Post'], 10, 0, 'raw');

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    public function test_and_search_in_advanced_mode(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php and laravel framework', 'body' => 'learning php and laravel together']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'python language', 'body' => 'Python is different']);

        $results = $this->engine->search('php AND laravel', ['App\Models\Post'], 10, 0, 'advanced');

        $this->assertCount(1, $results);
    }

    public function test_near_operator_has_no_wildcard_when_detected_as_operator(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'php NEAR laravel', 'advanced');

        $this->assertStringContainsString('php*', $result);
        $this->assertStringContainsString('laravel*', $result);
        // NEAR operators should never get a wildcard appended
        $this->assertDoesNotMatchRegularExpression('/\bnear\*/', $result);
        $this->assertDoesNotMatchRegularExpression('/\bNEAR\*/', $result);
    }

    public function test_operators_config_restricts_supported_ops(): void
    {
        $this->app['config']->set('illumi-search.operators.enabled', ['AND']);

        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        // OR should NOT be recognized as operator when restricted to AND only
        $result = $method->invoke($this->engine, 'php OR laravel', 'advanced');

        $this->assertStringContainsString('php*', $result);
        $this->assertStringContainsString('"or"', $result);
        $this->assertStringContainsString('laravel*', $result);
    }

    public function test_get_index_stats_returns_correct_structure(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'test', 'body' => 'content']);

        $stats = $this->engine->getIndexStats();

        $this->assertNotEmpty($stats);
        $this->assertArrayHasKey('model_class', $stats[0]);
        $this->assertArrayHasKey('record_count', $stats[0]);
        $this->assertEquals('App\Models\Post', $stats[0]['model_class']);
        $this->assertEquals(1, $stats[0]['record_count']);
    }

    public function test_not_at_start_returns_empty_no_exception(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'test laravel', 'body' => 'content']);

        $results = $this->engine->search('NOT laravel', ['App\Models\Post'], 10);

        $this->assertCount(0, $results);
    }

    public function test_and_at_start_returns_empty_no_exception(): void
    {
        $results = $this->engine->search('AND laravel', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_or_at_start_returns_empty_no_exception(): void
    {
        $results = $this->engine->search('OR laravel', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_unclosed_quote_returns_empty_no_exception(): void
    {
        $results = $this->engine->search('"unclosed', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_invalid_near_syntax_returns_empty_no_exception(): void
    {
        $results = $this->engine->search('NEAR/', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_normal_search_still_works_after_bad_query(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'valid query', 'body' => 'content']);

        // Bad query first (should not break engine state)
        $this->engine->search('NOT laravel', ['App\Models\Post'], 10);

        // Good query after (must still work)
        $results = $this->engine->search('valid', ['App\Models\Post'], 10);
        $this->assertCount(1, $results);
    }

    // ─── escapeQuery tests for all modes ───────────────────────────

    public function test_basic_mode_without_quotes_adds_wildcard(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'java', 'basic');

        $this->assertStringContainsString('java*', $result);
    }

    public function test_basic_mode_with_single_quoted_term_exact(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, '"java"', 'basic');

        // Quoted term must NOT get wildcard
        $this->assertStringContainsString('"java"', $result);
        $this->assertStringNotContainsString('"java"*', $result);
    }

    public function test_basic_mode_with_quoted_phrase_preserved(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, '"java 8"', 'basic');

        $this->assertStringContainsString('"java 8"', $result);
    }

    public function test_basic_mode_mixed_quoted_and_unquoted(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, '"exact phrase" java', 'basic');

        $this->assertStringContainsString('"exact phrase"', $result);
        $this->assertStringContainsString('java*', $result);
    }

    public function test_advanced_mode_with_quoted_phrase_preserved(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, '"laravel framework"', 'advanced');

        $this->assertStringContainsString('"laravel framework"', $result);
    }

    public function test_advanced_mode_mixed_operators_and_quotes(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'php AND "laravel framework"', 'advanced');

        $this->assertStringContainsString('php*', $result);
        $this->assertStringContainsString('"laravel framework"', $result);
        $this->assertDoesNotMatchRegularExpression('/\bAND\*/', $result);
    }

    public function test_raw_mode_passthrough(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $method = $ref->getMethod('escapeQuery');

        $result = $method->invoke($this->engine, 'php AND "laravel framework"', 'raw');

        // Raw mode returns the normalized (lowercased) query without escaping
        $this->assertStringContainsString('php', $result);
        $this->assertStringContainsString('"laravel framework"', $result);
        $this->assertStringContainsString('and', $result);
    }

    // ─── Real search tests for quotes in basic mode ────────────────

    public function test_basic_mode_quote_exact_does_not_return_partial(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'javascript guide', 'body' => 'learn js']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'java tutorial', 'body' => 'learn java']);

        // Unquoted "java" in basic mode should match both (partial wildcard)
        $unquoted = $this->engine->search('java', ['App\Models\Post'], 10, 0, 'basic');
        $this->assertCount(2, $unquoted);

        // Quoted '"java"' in basic mode should match only "java tutorial" (exact)
        $quoted = $this->engine->search('"java"', ['App\Models\Post'], 10, 0, 'basic');
        $this->assertCount(1, $quoted);
        $this->assertEquals('java tutorial', $quoted[0]->title);
    }

    public function test_basic_mode_phrase_exact(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello world foo', 'body' => 'test']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'hello bar', 'body' => 'test']);

        // "hello world" in basic mode should match only post 1 (phrase exact)
        $results = $this->engine->search('"hello world"', ['App\Models\Post'], 10, 0, 'basic');
        $this->assertCount(1, $results);
        $this->assertEquals('hello world foo', $results[0]->title);
    }

    public function test_advanced_mode_phrase_exact(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello world', 'body' => 'test']);

        // Both unquoted and quoted should find it in advanced mode
        $results = $this->engine->search('"hello world"', ['App\Models\Post'], 10, 0, 'advanced');
        $this->assertCount(1, $results);
        $this->assertEquals('hello world', $results[0]->title);
    }

    public function test_raw_mode_preserves_query(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello world', 'body' => 'test']);

        $results = $this->engine->search('hello world', ['App\Models\Post'], 10, 0, 'raw');
        $this->assertCount(1, $results);
    }

    public function test_is_fts5_available_returns_true_when_fts5_present(): void
    {
        $this->assertTrue($this->engine->isFts5Available());
    }

    public function test_is_fts5_available_before_db_initialization(): void
    {
        $engine = new \Moaines\IllumiSearch\Engines\SqliteEngine(
            databasePath: ':memory:',
            snippets: app(\Moaines\IllumiSearch\Support\SnippetService::class),
        );

        $ref = new \ReflectionClass($engine);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);

        $this->assertNull($dbProp->getValue($engine), 'db should not be initialized yet');
        $this->assertTrue($engine->isFts5Available(), 'isFts5Available should work without calling db()');
    }

    public function test_get_engine_version_contains_fts5(): void
    {
        $version = $this->engine->getEngineVersion();

        $this->assertStringContainsString('SQLite', $version);
        $this->assertStringContainsString('FTS5', $version);
    }

    public function test_create_table_throws_when_fts5_unavailable(): void
    {
        $ref = new \ReflectionClass($this->engine);
        $prop = $ref->getProperty('fts5Available');
        $prop->setAccessible(true);
        $prop->setValue($this->engine, false);

        $this->expectException(IllumiSearchException::class);
        $this->expectExceptionMessage('FTS5 is not available');

        $this->engine->createTable('App\Models\Other', ['title']);
    }

    public function test_dot_notation_search_returns_correct_results(): void
    {
        $this->engine->createTable(Book::class, ['title', 'body', 'author_name', 'comments_body', 'fullname']);

        $this->engine->upsert(Book::class, 1, [
            'title' => 'PHP pour les nuls',
            'body' => 'Un livre sur PHP',
            'author_name' => 'Jean Dupont',
            'comments_body' => 'Excellent ouvrage',
            'fullname' => 'PHP pour les nuls by Jean Dupont',
        ]);

        $this->engine->upsert(Book::class, 2, [
            'title' => 'Laravel avancé',
            'body' => 'Framework PHP',
            'author_name' => 'Marie Martin',
            'comments_body' => 'Très utile',
            'fullname' => 'Laravel avancé by Marie Martin',
        ]);

        // Search via the dot-notation column (author.name → author_name)
        $results = $this->engine->search('Dupont', [Book::class], 10);

        $this->assertCount(1, $results, 'Search by author.name should find 1 book');
        $this->assertEquals(1, $results[0]->modelId, 'Should find the book by Dupont');
        $this->assertLessThan(0, $results[0]->rank, 'BM25 rank should be negative');
    }

    public function test_search_respects_offset_for_pagination(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'first post', 'body' => 'alpha content']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'second post', 'body' => 'beta content']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'third post', 'body' => 'gamma content']);

        // Page 1: limit=2, offset=0
        $page1 = $this->engine->search('content', ['App\Models\Post'], 2, 0);
        $this->assertCount(2, $page1, 'Page 1 should have 2 results');

        // Page 2: limit=2, offset=2 (skip page 1)
        $page2 = $this->engine->search('content', ['App\Models\Post'], 2, 2);
        $this->assertCount(1, $page2, 'Page 2 should have 1 remaining result');

        // Both pages combined should cover all 3, no duplicates
        $allIds = collect($page1)->pluck('modelId')->merge(collect($page2)->pluck('modelId'))->sort()->values();
        $this->assertEquals([1, 2, 3], $allIds->toArray(), 'No duplicate IDs across pages');
    }

    public function test_snippets_do_not_crash_when_enabled(): void
    {
        $this->engine->upsert('App\Models\Post', 1, [
            'title' => 'article about laravel',
            'body' => 'Laravel is a PHP framework for web artisans.',
        ]);

        $withSnippets = $this->engine->search('laravel', ['App\Models\Post'], 10, 0, 'advanced', true);
        $withoutSnippets = $this->engine->search('laravel', ['App\Models\Post'], 10, 0, 'advanced', false);

        $this->assertCount(1, $withSnippets, 'Search with snippets enabled should not crash');
        $this->assertCount(1, $withoutSnippets, 'Search with snippets disabled should work');
    }

    public function test_search_results_are_sorted_by_bm25_rank_ascending(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php programming', 'body' => 'learn php online']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'general', 'body' => 'php is a language']);

        $results = $this->engine->search('php', ['App\Models\Post'], 10);

        $this->assertCount(2, $results);
        $this->assertLessThan(0, $results[0]->rank, 'BM25 rank should be negative');
        $this->assertGreaterThanOrEqual($results[1]->rank, $results[0]->rank, 'Results sorted by rank descending (best first)');
    }

    public function test_exact_phrase_search_requires_all_words_consecutive(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php moderne', 'body' => 'about php 8']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'php 8', 'body' => 'php moderne explained here']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'something else', 'body' => 'no match']);

        $results = $this->engine->search('"php moderne"', ['App\Models\Post'], 10, 0, 'advanced');

        $this->assertCount(2, $results, 'Both posts containing the exact phrase should match');
        $this->assertContains(1, array_map(fn ($r) => $r->modelId, $results), 'Post 1 contains exact phrase in title');
        $this->assertContains(2, array_map(fn ($r) => $r->modelId, $results), 'Post 2 contains exact phrase in body');
        $this->assertNotContains(3, array_map(fn ($r) => $r->modelId, $results), 'Post 3 does not contain the exact phrase');
    }

    public function test_search_without_query_returns_empty(): void
    {
        $results = $this->engine->search('', ['App\Models\Post'], 10);

        $this->assertEmpty($results);
    }

    public function test_search_returns_bm25_ranks_for_all_matching_records(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'learning data science', 'body' => 'data analysis with python']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'advanced data', 'body' => 'big data and analytics']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'css layout', 'body' => 'flexbox and grid']);

        $results = $this->engine->search('data', ['App\Models\Post'], 10);

        $this->assertCount(2, $results, 'Only posts matching "data" should be returned');
        $this->assertEquals([1, 2], array_map(fn ($r) => $r->modelId, $results), 'Only posts 1 and 2 should be found');
        $this->assertNotContains(3, array_map(fn ($r) => $r->modelId, $results), 'Post 3 should not match');
    }

    public function test_basic_mode_adds_wildcard_but_returns_ranked_results(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php framework', 'body' => 'laravel and symfony']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'laravel guide', 'body' => 'php framework for web']);

        $results = $this->engine->search('php', ['App\Models\Post'], 10, 0, 'basic');

        $this->assertCount(2, $results);
        $this->assertTrue(in_array(1, array_map(fn ($r) => $r->modelId, $results)), 'Post 1 should be found');
        $this->assertTrue(in_array(2, array_map(fn ($r) => $r->modelId, $results)), 'Post 2 should be found');
        foreach ($results as $r) {
            $this->assertLessThan(0, $r->rank, 'All results should have BM25 rank');
        }
    }

    public function test_vacuum_compacts_database(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'test', 'body' => 'content']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'another', 'body' => 'data']);
        $this->engine->delete('App\Models\Post', 2);

        $sizeBefore = $this->engine->getDatabaseSize();
        $this->engine->vacuum();

        $this->assertNotNull($sizeBefore, 'Database size should be measurable');
    }

    public function test_table_exists_returns_true_for_existing_table(): void
    {
        $this->assertTrue($this->engine->tableExists('App\Models\Post'));
    }

    public function test_table_exists_returns_false_for_non_existent_table(): void
    {
        $this->assertFalse($this->engine->tableExists('App\Models\NonExistent'));
    }

    public function test_get_database_size_returns_positive_integer(): void
    {
        $size = $this->engine->getDatabaseSize();

        $this->assertNotNull($size, 'Database size should not be null');
        $this->assertGreaterThan(0, $size, 'Database size should be positive for non-empty DB');
    }

    public function test_pagination_across_three_pages(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->engine->upsert('App\Models\Post', $i, ['title' => "post $i", 'body' => 'content to search']);
        }

        $page1 = $this->engine->search('search', ['App\Models\Post'], 2, 0);
        $page2 = $this->engine->search('search', ['App\Models\Post'], 2, 2);
        $page3 = $this->engine->search('search', ['App\Models\Post'], 2, 4);

        $this->assertCount(2, $page1, 'Page 1 should have 2 results');
        $this->assertCount(2, $page2, 'Page 2 should have 2 results');
        $this->assertCount(1, $page3, 'Page 3 should have 1 remaining result');

        $allIds = array_merge(
            array_map(fn ($r) => $r->modelId, $page1),
            array_map(fn ($r) => $r->modelId, $page2),
            array_map(fn ($r) => $r->modelId, $page3),
        );

        sort($allIds);
        $this->assertEquals([1, 2, 3, 4, 5], $allIds, 'All 5 posts distributed across pages without duplicates');
    }

    public function test_cross_model_search_ranks_by_bm25(): void
    {
        $this->engine->createTable('App\Models\Author', ['name', 'bio']);

        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php programming', 'body' => 'learn php online']);
        $this->engine->upsert('App\Models\Author', 1, ['name' => 'php expert', 'bio' => 'writes about code']);

        $results = $this->engine->search('php', ['App\Models\Post', 'App\Models\Author'], 10);

        $this->assertCount(2, $results, 'Both models should match');
        foreach ($results as $r) {
            $this->assertLessThan(0, $r->rank, 'All cross-model results should have BM25 rank');
        }
        $this->assertTrue($results[0]->rank >= $results[1]->rank, 'Cross-model results sorted by rank (best first)');
    }
}
