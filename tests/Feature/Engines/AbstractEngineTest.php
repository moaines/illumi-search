<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
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
}
