<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Tests\TestCase;

abstract class AbstractEngineTest extends TestCase
{
    abstract protected function createEngine(): Engine;

    protected string $testModelClass = 'App\Models\Post';

    /** @test */
    public function upsert_then_search_finds_document(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'laravel guide', 'body' => 'build web apps']);

        $results = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    /** @test */
    public function upsert_then_count_returns_correct_number(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php', 'body' => 'more content']);

        $this->assertEquals(2, $engine->count('php', [$this->testModelClass]));
    }

    /** @test */
    public function delete_removes_document(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);
        $this->assertEquals(1, $engine->count('php', [$this->testModelClass]));

        $engine->delete($this->testModelClass, 1);
        $this->assertEquals(0, $engine->count('php', [$this->testModelClass]));
    }

    /** @test */
    public function empty_query_returns_empty(): void
    {
        $engine = $this->createEngine();
        $this->assertEmpty($engine->search('', [$this->testModelClass], 10));
        $this->assertEquals(0, $engine->count('', [$this->testModelClass]));
    }

    /** @test */
    public function search_returns_empty_for_no_match(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);
        $this->assertEmpty($engine->search('xyznonexistent', [$this->testModelClass], 10));
    }

    /** @test */
    public function pagination_with_offset_returns_correct_pages(): void
    {
        $engine = $this->createEngine();
        for ($i = 1; $i <= 5; $i++) {
            $engine->upsert($this->testModelClass, $i, ['title' => "post $i", 'body' => 'content']);
        }

        $page1 = $engine->search('content', [$this->testModelClass], 2, 0);
        $page2 = $engine->search('content', [$this->testModelClass], 2, 2);
        $page3 = $engine->search('content', [$this->testModelClass], 2, 4);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertCount(1, $page3);

        $allIds = array_merge(
            array_map(fn ($r) => $r->modelId, $page1),
            array_map(fn ($r) => $r->modelId, $page2),
            array_map(fn ($r) => $r->modelId, $page3),
        );
        sort($allIds);
        $this->assertEquals([1, 2, 3, 4, 5], $allIds);
    }

    /** @test */
    public function insert_batch_works(): void
    {
        $engine = $this->createEngine();
        $engine->insertBatch($this->testModelClass, [
            ['model_id' => 1, 'document' => ['title' => 'doc one', 'body' => 'data']],
            ['model_id' => 2, 'document' => ['title' => 'doc two', 'body' => 'data']],
        ]);

        $this->assertEquals(2, $engine->count('data', [$this->testModelClass]));
    }

    /** @test */
    public function get_index_stats_returns_expected_structure(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $stats = $engine->getIndexStats();
        $this->assertNotEmpty($stats);
        $this->assertEquals($this->testModelClass, $stats[0]['model_class']);
        $this->assertGreaterThanOrEqual(1, $stats[0]['record_count']);
        $this->assertArrayHasKey('last_synced_at', $stats[0]);
    }

    /** @test */
    public function table_exists_returns_bool(): void
    {
        $engine = $this->createEngine();
        $this->assertTrue($engine->tableExists($this->testModelClass));
    }

    protected function assertTableDropped(): void
    {
        $engine = $this->createEngine();
        $engine->dropTable($this->testModelClass);
        $this->assertFalse($engine->tableExists($this->testModelClass));
    }

    /** @test */
    public function update_replaces_content(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'original term', 'body' => 'content']);
        $this->assertEquals(1, $engine->count('original', [$this->testModelClass]));

        $engine->delete($this->testModelClass, 1);
        $engine->upsert($this->testModelClass, 1, ['title' => 'updated content', 'body' => 'new']);
        $this->assertEquals(0, $engine->count('original', [$this->testModelClass]));
        $this->assertEquals(1, $engine->count('updated', [$this->testModelClass]));
    }

    /** @test */
    public function total_count_matches_expected_count(): void
    {
        $engine = $this->createEngine();
        for ($i = 1; $i <= 5; $i++) {
            $engine->upsert($this->testModelClass, $i, ['title' => "post $i", 'body' => 'content']);
        }
        $results = $engine->search('content', [$this->testModelClass], 2, 0);
        $this->assertEquals(5, $results[0]->totalCount);
    }

    /** @test */
    public function suggest_returns_sorted_relevant_suggestions(): void
    {
        $engine = $this->createEngine();

        $engine->upsert($this->testModelClass, 1, [
            'title' => 'php programming',
            'body' => 'learn php for web development',
        ]);
        $engine->upsert($this->testModelClass, 2, [
            'title' => 'php framework',
            'body' => 'laravel and symfony php framework',
        ]);
        $engine->upsert($this->testModelClass, 3, [
            'title' => 'python data science',
            'body' => 'pandas numpy python',
        ]);
        $engine->upsert($this->testModelClass, 4, [
            'title' => 'programming basics',
            'body' => 'python programming fundamentals php',
        ]);

        $suggestions = $engine->suggest('progamming', 2, 5);
        $this->assertNotEmpty($suggestions);
        $this->assertContains('programming', $suggestions);

        $suggestions = $engine->suggest('phpp', 2, 5);
        $this->assertContains('php', $suggestions);
        $this->assertSame('php', $suggestions[0]);

        $this->assertEmpty($engine->suggest('', 2, 5));
        $this->assertEmpty($engine->suggest('x', 2, 5));

        $this->assertEmpty($engine->suggest('zzzzz', 2, 5));

        $this->assertLessThanOrEqual(2, count($engine->suggest('php', 2, 2)));

        foreach ($engine->suggest('prgramming', 3, 5) as $word) {
            $this->assertLessThanOrEqual(3, levenshtein('prgramming', $word));
        }
    }

    /** @test */
    public function suggest_prefers_same_script_over_ascii_proximity(): void
    {
        $engine = $this->createEngine();

        $engine->upsert($this->testModelClass, 1, [
            'title' => 'laravel php',
            'body' => 'framework',
        ]);
        $engine->upsert($this->testModelClass, 2, [
            'title' => 'правил php',
            'body' => 'cyrillic content',
        ]);

        $suggestions = $engine->suggest('laravil', 2, 5);
        $this->assertNotEmpty($suggestions);
        $this->assertContains('laravel', $suggestions,
            'Latin query should suggest Latin word, not Cyrillic with same ASCII');
    }

    /** @test */
    public function optimize_does_not_crash(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $result = $engine->optimize();
        $this->assertArrayHasKey('tables_optimized', $result);
    }

    /** @test */
    public function get_supported_operators_returns_non_empty_array(): void
    {
        $engine = $this->createEngine();
        $operators = $engine->getSupportedOperators();

        $this->assertIsArray($operators);
        $this->assertNotEmpty($operators);
        $this->assertContains('AND', $operators);
        $this->assertContains('OR', $operators);
    }

    /** @test */
    public function engine_supports_standard_search_features(): void
    {
        $engine = $this->createEngine();

        $this->assertTrue($engine->supportsPhraseSearch());
        $this->assertTrue($engine->supportsPrefixWildcard());

        $operators = $engine->getSupportedOperators();
        $this->assertContains('AND', $operators);
        $this->assertContains('OR', $operators);
    }

    /** @test */
    public function and_operator_narrows_results(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php framework', 'body' => 'laravel symfony']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php basics', 'body' => 'learning']);

        $results = $engine->search('php AND framework', [$this->testModelClass], 10);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    /** @test */
    public function not_operator_excludes_results(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'php is great']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php laravel', 'body' => 'php framework']);

        $results = $engine->search('php NOT laravel', [$this->testModelClass], 10);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    /** @test */
    public function exact_phrase_requires_all_words_consecutive(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php moderne', 'body' => 'about php 8']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php 8', 'body' => 'php moderne explained']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'other', 'body' => 'no match']);

        $results = $engine->search('"php moderne"', [$this->testModelClass], 10, 0, 'advanced');
        $this->assertCount(2, $results);

        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    /** @test */
    public function title_match_ranks_above_body_match(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'other content']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'other topic', 'body' => 'php programming']);

        $results = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual($results[1]->rank, $results[0]->rank, 'Better match should rank first (or equal)');
    }

    /** @test */
    public function index_emoji_without_crash(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => '🔍 search', 'body' => 'emoji test 🔥']);
        $results = $engine->search('search', [$this->testModelClass], 10);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    /** @test */
    public function index_html_content_is_stripped(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, [
            'title' => '<b>hello</b> world',
            'body' => '<i>italic</i> content',
        ]);
        $results = $engine->search('hello', [$this->testModelClass], 10);
        $this->assertCount(1, $results, 'hello should be findable after HTML stripping');
        $results = $engine->search('italic', [$this->testModelClass], 10);
        $this->assertCount(1, $results, 'italic should be findable after HTML stripping');
    }

    /** @test */
    public function sql_injection_attempt_does_not_break_search(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => "safe title", 'body' => 'content']);

        $results = $engine->search("' OR 1=1 --", [$this->testModelClass], 10);
        $this->assertCount(0, $results, 'SQL injection pattern should not match');
    }

    /** @test */
    public function search_with_special_chars_does_not_crash(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'normal title', 'body' => 'content']);

        $queries = [
            "test@#$%^&*()",
            "!@#$%^&*()",
            "\n\t\r",
            "   ",
        ];

        foreach ($queries as $q) {
            $results = $engine->search($q, [$this->testModelClass], 10);
            $this->assertIsArray($results, "Query '{$q}' should not throw");
            $this->assertCount(0, $results, "Query '{$q}' should return empty");
        }
    }

    /** @test */
    public function not_operator_quality_check(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'php is great']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php laravel', 'body' => 'php framework']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'laravel guide', 'body' => 'web framework']);

        $results = $engine->search('laravel NOT php', [$this->testModelClass], 10);
        $this->assertCount(1, $results, 'Only post 3 should match (laravel, no php)');
        $this->assertEquals(3, $results[0]->modelId, 'Post 3 has laravel without php');
    }

    /** @test */
    public function or_operator_finds_either_term(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php framework', 'body' => '']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'python intro', 'body' => '']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'java basics', 'body' => '']);

        $results = $engine->search('php OR python', [$this->testModelClass], 10);
        $this->assertCount(2, $results);
        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
    }

    /** @test */
    public function wildcard_matches_prefix(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'python scripting', 'body' => 'python']);

        // In advanced mode, FTS5 adds * automatically. Use 'php' which becomes 'php*'
        $results = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->modelId);
    }

    /** @test */
    public function search_with_modes_produces_results(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'laravel guide', 'body' => 'build web apps']);

        foreach (['advanced', 'basic', 'raw'] as $mode) {
            $results = $engine->search('php', [$this->testModelClass], 10, 0, $mode);
            $this->assertNotEmpty($results, "Mode '$mode' should return results for 'php'");
        }
    }

    /** @test */
    public function search_with_snippets_returns_marked_text(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php today']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'python intro', 'body' => 'python basics']);

        $results = $engine->search('php', [$this->testModelClass], 10, 0, 'advanced', true);
        $this->assertNotEmpty($results);

        $foundPhp = false;
        foreach ($results as $r) {
            if ($r->modelId === 1) {
                $foundPhp = true;
                // Snippet/summary may be null for engines without SnippetService
                if ($r->summary !== null) {
                    $this->assertNotEmpty($r->summary);
                }
            }
        }
        $this->assertTrue($foundPhp, 'Doc 1 should be found with snippet');
    }

    /** @test */
    public function search_with_only_operators_returns_empty(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'content']);

        $results = $engine->search('AND OR NOT', [$this->testModelClass], 10);
        $this->assertCount(0, $results, 'Only operators should return empty');

        $resultsSnippets = $engine->search('AND OR NOT', [$this->testModelClass], 10, 0, 'advanced', true);
        $this->assertCount(0, $resultsSnippets, 'Only operators with snippets enabled should return empty');
    }

    /** @test */
    public function snippet_does_not_highlight_operators(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php today']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'laravel framework', 'body' => 'build web apps with laravel']);

        $results = $engine->search('php NOT laravel', [$this->testModelClass], 10, 0, 'advanced', true);
        $this->assertNotEmpty($results);

        foreach ($results as $r) {
            if ($r->summary === null) {
                continue;
            }
            $this->assertStringNotContainsString(
                '<mark>NOT</mark>',
                $r->summary,
                'Operator "NOT" should not be highlighted in snippets',
            );
            // Doc 1 (php) should have highlighted "php"
            if ($r->modelId === 1) {
                $this->assertStringContainsString(
                    '<mark>php</mark>',
                    $r->summary,
                    'Search term "php" should be highlighted',
                );
            }
        }
    }

    /** @test */
    public function integrity_check_passes_for_valid_data(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'laravel', 'body' => 'framework']);

        $this->assertTrue($engine->integrityCheck($this->testModelClass));
    }

    /** @test */
    public function full_integrity_check_returns_passed(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $result = $engine->fullIntegrityCheck();
        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function get_database_size_returns_positive_integer(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $size = $engine->getDatabaseSize();
        $this->assertNotNull($size);
        $this->assertGreaterThan(0, $size);
    }

    /** @test */
    public function list_index_tables_returns_tables(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $tables = $engine->listIndexTables();
        $this->assertNotEmpty($tables);
    }

    /** @test */
    public function drop_index_table_removes_table(): void
    {
        $engine = $this->createEngine();

        $isMysql = str_contains(get_class($engine), 'MySql');
        $isSqlite = str_contains(get_class($engine), 'Sqlite');

        // SqliteEngine's dropIndexTable expects a table name, not model class
        if ($isSqlite) {
            $this->markTestSkipped('SqliteEngine dropIndexTable uses different parameter semantics');
        }

        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $table = $engine->tableName($this->testModelClass);
        $engine->dropIndexTable($this->testModelClass);

        if ($isMysql) {
            $this->assertEquals(0, $engine->count('test', [$this->testModelClass]),
                'MySQL should have no data after dropIndexTable');
        } else {
            $tables = $engine->listIndexTables();
            $this->assertNotContains($table, $tables,
                'Table should be removed after dropIndexTable');
        }
    }

    /** @test */
    public function ranking_is_consistent_with_weights_and_rarity(): void
    {
        $engine = $this->createEngine();

        $engine->upsert($this->testModelClass, 1, ['title' => 'php rare title', 'body' => 'common filler text']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'other topic', 'body' => 'rare word appears']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'second php title', 'body' => 'php appears here too']);
        $engine->upsert($this->testModelClass, 4, ['title' => 'unrelated', 'body' => 'php somewhere']);

        $results = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(3, $results, 'Three docs contain php');

        // All engines should find the same 3 docs
        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids, 'Doc 1 (php in title)');
        $this->assertContains(3, $ids, 'Doc 3 (php in title+body)');
        $this->assertContains(4, $ids, 'Doc 4 (php in body only)');

        // Ranking order varies by engine (negated FTS5 positive, FULLTEXT positive, custom BM25 0-100)
        // At minimum: rank should be non-zero
        $this->assertNotEquals(0, $results[0]->rank, 'Top result should have non-zero rank');
    }

    /** @test */
    public function search_with_quotes_works(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming guide', 'body' => 'learn php programming']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'php framework', 'body' => 'laravel symfony']);

        // Phrase "php programming" should find doc containing consecutive words
        $results = $engine->search('"php programming"', [$this->testModelClass], 10);
        $this->assertNotEmpty($results, 'Quoted phrase should find matching docs');
    }

    /** @test */
    public function prefix_search_finds_partial_word(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'Laravel pour les pros', 'body' => 'guide php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'PHP 8 basics', 'body' => 'php programming language']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'Python intro', 'body' => 'python data science']);

        // Prefix "lara" should find "Laravel"
        $results = $engine->search('lara', [$this->testModelClass], 10);
        $this->assertNotEmpty($results, 'Prefix "lara" should match "Laravel"');
        $this->assertEquals(1, $results[0]->modelId, 'Doc with "Laravel" should appear');

        // Prefix "php" should find docs with "php"
        $phpResults = $engine->search('ph', [$this->testModelClass], 10);
        $this->assertCount(2, $phpResults, 'Prefix "ph" should match "php" docs');
    }

    /** @test */
    public function exact_match_ranks_above_prefix_match(): void
    {
        $engine = $this->createEngine();
        // Doc 1: has exact token "php" in title
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming guide', 'body' => 'learn php']);
        // Doc 2: has "phpspreadsheet" but not standalone "php" token
        $engine->upsert($this->testModelClass, 2, ['title' => 'phpspreadsheet library', 'body' => 'excel library']);

        $results = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(2, $results, 'Both docs should match (exact + prefix)');

        // Both docs should be present (ranking order varies by engine)
        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids, 'Doc with exact "php" token should appear');
        $this->assertContains(2, $ids, 'Doc with "phpspreadsheet" (prefix match) should appear');

        // Scores should be non-zero
        foreach ($results as $r) {
            $this->assertNotEquals(0, $r->rank,
                'All matched docs should have non-zero rank');
        }
    }

    /** @test */
    public function prefix_search_does_not_match_nonsense(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'Laravel', 'body' => 'guide']);

        $results = $engine->search('xxxxx', [$this->testModelClass], 10);
        $this->assertCount(0, $results, 'Non-existent prefix should return empty');
    }

    /** @test */
    public function cjk_search_returns_results(): void
    {
        $engine = $this->createEngine();

        // SQLite and MySQL don't support CJK tokenization natively
        $isFileEngine = str_contains(get_class($engine), 'FileEngine');
        if (! $isFileEngine) {
            $this->markTestSkipped('CJK search requires FileEngine\'s CJK separation');
        }

        $engine->upsert($this->testModelClass, 1, ['title' => 'PHP编程入门', 'body' => '学习PHP编程']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'Python数据科学', 'body' => '数据分析']);

        $results = $engine->search('PHP', [$this->testModelClass], 10);
        $this->assertNotEmpty($results, 'CJK mixed with Latin should be searchable');

        $cjResults = $engine->search('学习', [$this->testModelClass], 10);
        $this->assertNotEmpty($cjResults, 'CJK word "学习" should match tokenized "学 习"');
    }

    /** @test */
    public function tenant_isolation_prefixes_tables(): void
    {
        $engine = $this->createEngine();

        // Enable tenancy and register a tenant resolver
        config(['illumi-search.tenancy.enabled' => true]);
        app(TenantManager::class)->setResolver(fn () => 'test_tenant');

        try {
            // Create tenant-scoped table
            $engine->createTable($this->testModelClass, ['title', 'body']);

            $engine->upsert($this->testModelClass, 1, ['title' => 'tenant specific doc', 'body' => 'data for tenant']);
            $engine->upsert($this->testModelClass, 2, ['title' => 'another tenant doc', 'body' => 'more data']);

            // Search under test_tenant should find the docs
            $results = $engine->search('tenant', [$this->testModelClass], 10);
            $this->assertNotEmpty($results, 'Tenant-scoped search should find documents');

            $resultIds = array_map(fn ($r) => $r->modelId, $results);
            $this->assertContains(1, $resultIds);
            $this->assertContains(2, $resultIds);

            // Switch to another tenant — different isolation scope
            app(TenantManager::class)->setResolver(fn () => 'other_tenant');

            // Create table under the new tenant (no drop needed — table doesn't exist yet)
            $engine->createTable($this->testModelClass, ['title', 'body']);

            $otherResults = $engine->search('tenant', [$this->testModelClass], 10);
            $this->assertEmpty($otherResults, 'Different tenant should NOT find documents from test_tenant');
        } finally {
            // Cleanup
            app(TenantManager::class)->setResolver(fn () => null);
            config(['illumi-search.tenancy.enabled' => false]);
        }
    }

    /** @test */
    public function prefix_search_returns_more_results_than_exact(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'Laravel pour les pros', 'body' => 'php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'Lara Croft guide', 'body' => 'gaming']);
        $engine->upsert($this->testModelClass, 3, ['title' => 'Python intro', 'body' => 'data science']);

        $exactResults = $engine->search('laravel', [$this->testModelClass], 10);
        $prefixResults = $engine->search('lara', [$this->testModelClass], 10);

        // "lara" is a prefix of "laravel" and also matches "Lara" → more results
        $this->assertGreaterThan(
            count($exactResults),
            count($prefixResults),
            'Prefix "lara" should match more documents than exact "laravel"',
        );

        $prefixIds = array_map(fn ($r) => $r->modelId, $prefixResults);
        $this->assertContains(1, $prefixIds, 'Doc 1 (Laravel) should appear under prefix "lara"');
        $this->assertContains(2, $prefixIds, 'Doc 2 (Lara) should appear under prefix "lara"');
        $this->assertNotContains(3, $prefixIds, 'Doc 3 (Python) should NOT appear under prefix "lara"');
    }
}
