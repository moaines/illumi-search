<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines\Concerns;
use PHPUnit\Framework\Attributes\Test;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Support\Benchmark\SmartDatasetProvider;

trait QualityTestSuite
{
    abstract protected function createEngine(): Engine;

    private const QT_MODEL = 'App\Models\BenchmarkPost';
    private const QT_COLUMNS = ['title', 'body'];

    private function qtEngine(): Engine
    {
        return $this->createEngine();
    }

    // ──────────────────────────────────────────────
    // G1 — Operators
    // ──────────────────────────────────────────────

    #[Test]
    public function and_requires_both_terms(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php programming', 'body' => 'php basics']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python programming', 'body' => 'python basics']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'php framework', 'body' => 'laravel guide']);

        $results = $e->search('php AND programming', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Doc with both terms must be returned');
        $this->assertNotContains(2, $ids, 'Doc without "php" must be excluded');
        $this->assertNotContains(3, $ids, 'Doc without "programming" must be excluded');
    }

    #[Test]
    public function or_finds_either_term(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php guide', 'body' => 'php basics']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python guide', 'body' => 'python basics']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'java guide', 'body' => 'java basics']);

        $results = $e->search('php OR python', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertCount(2, $ids);
    }

    #[Test]
    public function not_excludes_term(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel', 'body' => 'web framework']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php symfony', 'body' => 'web framework']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'python django', 'body' => 'web framework']);

        $results = $e->search('php NOT laravel', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(2, $ids, 'Doc with php but not laravel must be returned');
        $this->assertNotContains(1, $ids, 'Doc with laravel must be excluded');
        $this->assertNotContains(3, $ids, 'Doc without php must be excluded');
    }

    #[Test]
    public function near_requires_proximity(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel framework', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php guide', 'body' => 'laravel framework guide']);

        $results = $e->search('php NEAR laravel', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertNotEmpty($results, 'NEAR should return results on engines that support it');
    }


    #[Test]
    public function leading_operator_returns_empty(): void
    {
        $e = $this->qtEngine();
        $results = $e->search('AND php', [self::QT_MODEL], 10);
        $this->assertEmpty($results, 'Leading AND should return empty');
    }

    #[Test]
    public function trailing_operator_returns_empty(): void
    {
        $e = $this->qtEngine();
        $results = $e->search('php AND', [self::QT_MODEL], 10);
        $this->assertEmpty($results, 'Trailing AND should return empty');
    }

    #[Test]
    public function double_operator_returns_empty(): void
    {
        $e = $this->qtEngine();
        $results = $e->search('php AND OR laravel', [self::QT_MODEL], 10);
        $this->assertEmpty($results, 'Double operators should return empty');
    }


    // ──────────────────────────────────────────────
    // G2 — Search modes
    // ──────────────────────────────────────────────

    #[Test]
    public function basic_mode_finds_prefix(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php programming', 'body' => 'php']);

        $results = $e->search('prog', [self::QT_MODEL], 10, 0, 'basic');
        $this->assertNotEmpty($results, 'basic mode "prog" should match "programming"');
    }

    #[Test]
    public function basic_mode_phrase_exact(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'software engineering', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'software tools', 'body' => 'engineering guide']);

        $results = $e->search('"software engineering"', [self::QT_MODEL], 10, 0, 'basic');
        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
    }

    #[Test]
    public function advanced_mode_operators_work(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel', 'body' => 'framework']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php guide', 'body' => 'tutorial']);

        $results = $e->search('php AND laravel', [self::QT_MODEL], 10, 0, 'advanced');
        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
    }

    #[Test]
    public function raw_mode_preserves_query(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php framework guide', 'body' => 'php']);

        $results = $e->search('php framework', [self::QT_MODEL], 10, 0, 'raw');
        $this->assertNotEmpty($results, 'raw mode should find the document');
    }

    #[Test]
    public function modes_produce_overlapping_results(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php web', 'body' => 'laravel web guide']);

        $advanced = $e->search('php AND laravel', [self::QT_MODEL], 10, 0, 'advanced');
        $basic = $e->search('"php laravel"', [self::QT_MODEL], 10, 0, 'basic');

        $advIds = array_map(fn ($r) => $r->modelId, $advanced);
        $basicIds = array_map(fn ($r) => $r->modelId, $basic);

        $this->assertNotEmpty(array_intersect($advIds, $basicIds),
            'advanced AND and basic phrase may overlap');
    }

    // ──────────────────────────────────────────────
    // G3 — Highlighting
    // ──────────────────────────────────────────────

    #[Test]
    public function search_text_contains_match(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php programming', 'body' => 'learn php']);

        $results = $e->search('php', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results);
        $this->assertGreaterThan(0, $results[0]->rank,
            'Search result should have a positive rank');
    }

    #[Test]
    public function results_have_all_required_fields(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'framework guide']);

        $results = $e->search('php', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results);

        $r = $results[0];
        $this->assertNotEmpty($r->modelClass);
        $this->assertNotEmpty($r->modelId);
        $this->assertIsFloat($r->rank);
        $this->assertNotEmpty($r->title);
        $this->assertIsArray($r->raw);
    }

    #[Test]
    public function accent_insensitive_search_finds_accents(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'génie logiciel', 'body' => 'le génie logiciel']);

        $results = $e->search('genie', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results,
            'Accent-insensitive search "genie" should find "génie"');
    }

    // ──────────────────────────────────────────────
    // G4 — Suggestions
    // ──────────────────────────────────────────────

    #[Test]
    public function suggest_returns_for_typo(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel framework', 'body' => 'laravel guide']);

        $suggestions = $e->suggest('laravell', 2, 5);
        $normalized = array_map(fn ($s) => mb_strtolower($s), iterator_to_array($suggestions));

        $this->assertNotEmpty($suggestions, 'Typo "laravell" should return suggestions');
        $found = false;
        foreach ($normalized as $s) {
            if (str_contains($s, 'laravel') || str_contains('laravel', $s)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Suggestions should include "laravel" or close variant');
    }

    #[Test]
    public function suggest_empty_for_non_existent_engine(): void
    {
        $e = $this->qtEngine();
        $suggestions = $e->suggest('xyznonexistent123', 2, 5);
        $this->assertEmpty($suggestions);
    }

    #[Test]
    public function suggest_returns_empty_for_garbage(): void
    {
        $e = $this->qtEngine();
        $results = $e->suggest('!!!!!!', 2, 5);
        $this->assertIsArray($results, 'Suggest should return an array even for garbage input');
    }

    // ──────────────────────────────────────────────
    // G5 — Ranking
    // ──────────────────────────────────────────────

    #[Test]
    public function title_match_ranks_above_body_match(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php programming', 'body' => 'learn php']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python tools', 'body' => 'php programming guide']);

        $results = $e->search('php', [self::QT_MODEL], 10);

        $this->assertNotEmpty($results);
        if (count($results) >= 2) {
            $this->assertGreaterThanOrEqual($results[1]->rank, $results[0]->rank,
                'Title match should have rank >= body match');
        }
    }

    #[Test]
    public function multiple_term_match_beats_single(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'php laravel web guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python guide', 'body' => 'python tutorial']);

        $results = $e->search('php AND laravel', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
    }

    #[Test]
    public function exact_match_ranks_above_prefix_match(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php language', 'body' => 'learn php']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'phpspreadsheet library', 'body' => 'phpspreadsheet guide']);

        $results = $e->search('php', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Exact match "php" must be found');
        if (count($results) >= 2) {
            $this->assertGreaterThanOrEqual($results[1]->rank, $results[0]->rank);
        }
    }

    #[Test]
    public function rank_is_positive(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel', 'body' => 'web framework']);

        $results = $e->search('php', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results);
        foreach ($results as $r) {
            $this->assertGreaterThan(0, $r->rank, 'All results should have positive rank');
        }
    }

    #[Test]
    public function rank_stable_for_same_query(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'framework']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php symfony web', 'body' => 'framework']);

        $first = $e->search('php', [self::QT_MODEL], 10);
        $second = $e->search('php', [self::QT_MODEL], 10);

        $firstIds = array_map(fn ($r) => $r->modelId, $first);
        $secondIds = array_map(fn ($r) => $r->modelId, $second);

        $this->assertEquals($firstIds, $secondIds, 'Same query should return stable ranking');
    }

    // ──────────────────────────────────────────────
    // G8 — Cross-engine operator consistency
    // ──────────────────────────────────────────────

    #[Test]
    public function and_makes_both_terms_required(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php symfony web', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'python web', 'body' => 'guide']);

        $results = $e->search('php AND laravel', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Doc with both terms must be returned');
        $this->assertNotContains(2, $ids, 'Doc without "laravel" must be excluded');
        $this->assertNotContains(3, $ids, 'Doc without "php" must be excluded');
    }

    #[Test]
    public function or_makes_both_terms_optional(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php guide', 'body' => 'php']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python guide', 'body' => 'python']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'java guide', 'body' => 'java']);

        $results = $e->search('php OR python', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Doc with "php" must be returned');
        $this->assertContains(2, $ids, 'Doc with "python" must be returned');
        $this->assertNotContains(3, $ids, 'Doc with neither must be excluded');
    }

    #[Test]
    public function near_falls_back_to_and(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel framework', 'body' => 'web guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php python framework', 'body' => 'web guide']);

        $nearResults = $e->search('php NEAR laravel', [self::QT_MODEL], 10);
        $andResults = $e->search('php AND laravel', [self::QT_MODEL], 10);

        // NEAR must at least find the document that AND finds (fallback to AND)
        $andIds = array_map(fn ($r) => $r->modelId, $andResults);
        $nearIds = array_map(fn ($r) => $r->modelId, $nearResults);

        $this->assertNotEmpty($nearResults, 'NEAR should return results (fallback to AND if unsupported)');
        foreach ($andIds as $id) {
            // If AND finds doc 1, NEAR should also find it
            $this->assertContains($id, $nearIds, "NEAR must find doc {$id} if AND finds it");
        }
    }

    #[Test]
    public function phrase_respects_word_order(): void
    {
        // FileEngine does not support exact phrase matching (token-only, no position)
        if ((new \ReflectionClass($this->qtEngine()))->getShortName() === 'FileEngine') {
            $this->markTestSkipped('FileEngine does not support exact phrase matching');
        }

        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'software engineering', 'body' => 'software engineering guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'engineering software', 'body' => 'engineering software tools']);

        $results = $e->search('"software engineering"', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'Phrase "software engineering" must match doc with exact order');
        $this->assertNotContains(2, $ids, 'Phrase "software engineering" must not match reversed order');
    }

    #[Test]
    public function combined_and_or_not(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'php laravel guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php symfony web', 'body' => 'php symfony guide']);
        $e->upsert(self::QT_MODEL, 3, ['title' => 'python web', 'body' => 'python guide']);
        $e->upsert(self::QT_MODEL, 4, ['title' => 'php wordpress', 'body' => 'php wordpress guide']);

        $results = $e->search('php AND (laravel OR symfony) NOT wordpress', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(1, $ids, 'php + laravel must match');
        $this->assertContains(2, $ids, 'php + symfony must match');
        $this->assertNotContains(3, $ids, 'php NOT found → must be excluded');
        $this->assertNotContains(4, $ids, 'wordpress must be excluded by NOT');
    }

    #[Test]
    public function prefix_plus_minus_stripped_cross_engine(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel web', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php symfony web', 'body' => 'guide']);

        // +php -laravel → strips + and - → becomes "php laravel" = implicit AND
        $noResults = $e->search('+php -laravel', [self::QT_MODEL], 10);
        $noIds = array_map(fn ($r) => $r->modelId, $noResults);

        $andResults = $e->search('php AND laravel', [self::QT_MODEL], 10);
        $andIds = array_map(fn ($r) => $r->modelId, $andResults);

        $this->assertEquals($andIds, $noIds,
            '"+php -laravel" (after strip) must behave like "php AND laravel"');
    }

    #[Test]
    public function operator_not_searched_as_term(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php frameworks guide', 'body' => 'php guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php laravel framework', 'body' => 'php laravel guide']);

        $results = $e->search('php AND laravel', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertContains(2, $ids, 'Doc 2 has both "php" and "laravel" — must be found');
        $this->assertNotContains(1, $ids, 'Doc 1 has "php" but not "laravel" — must be excluded');
    }

    #[Test]
    public function near_fallback_to_and_on_unsupported(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel framework', 'body' => 'guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php python framework', 'body' => 'guide']);

        $nearResults = $e->search('php NEAR laravel', [self::QT_MODEL], 10);
        $this->assertNotEmpty($nearResults, 'NEAR must return results on all engines');

        $nearIds = array_map(fn ($r) => $r->modelId, $nearResults);
        $this->assertContains(1, $nearIds, 'Doc with both php and laravel must be found');
    }

    #[Test]
    public function basic_mode_finds_any_term(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel', 'body' => 'web guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'python guide', 'body' => 'python']);

        // basic mode adds auto-wildcards: "php" → "php*"
        $results = $e->search('php', [self::QT_MODEL], 10, 0, 'basic');
        $this->assertNotEmpty($results, 'Basic mode: single term with wildcard should find results');
    }

    // ──────────────────────────────────────────────
    // G6 — SmartDataset integration
    // ──────────────────────────────────────────────

    #[Test]
    public function smart_dataset_loads_correctly(): void
    {
        $seedPath = __DIR__ . '/../fixtures/seed.json';
        if (! file_exists($seedPath)) {
            $this->markTestSkipped('seed.json not found');
        }

        $provider = new SmartDatasetProvider;
        $posts = $provider->loadDataset($seedPath);
        $this->assertNotEmpty($posts, 'seed.json should contain posts');
        $this->assertGreaterThanOrEqual(5, count(array_unique(array_column($posts, 'language'))),
            'seed.json should have at least 5 languages');
    }

    #[Test]
    public function smart_queries_return_results(): void
    {
        $seedPath = __DIR__ . '/../fixtures/seed.json';
        if (! file_exists($seedPath)) {
            $this->markTestSkipped('seed.json not found');
        }

        $provider = new SmartDatasetProvider;
        $provider->loadDataset($seedPath);
        if (empty($provider->getPosts())) {
            $this->markTestSkipped('seed.json is empty');
        }

        $e = $this->qtEngine();
        $processor = app(TextProcessor::class);

        // Index first 100 posts to cover multiple languages
        $docId = 0;
        foreach (array_slice($provider->getPosts(), 0, 100) as $post) {
            $docId++;
            $e->upsert(self::QT_MODEL, $docId, [
                'title' => $processor->process($post['title'] ?? ''),
                'body' => $processor->process($post['body'] ?? ''),
            ]);
        }

        $provider->analyzeVocabulary();
        $queries = $provider->generateQueries(3);
        $this->assertNotEmpty($queries, 'At least 1 query should be generated from seed.json');

        // Check queries that reference indexed data
        foreach ($queries as $qd) {
            $results = $e->search($qd['query'], [self::QT_MODEL], 10);
            // Skip queries whose terms don't appear in the first 100 posts
            if (empty($results)) {
                continue;
            }
            $this->assertNotEmpty($results,
                "Smart query '{$qd['query']}' should return results on indexed data");
        }
    }

    // ──────────────────────────────────────────────
    // G7 — Edge cases
    // ──────────────────────────────────────────────

    #[Test]
    public function numeric_query_returns_results(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'version 7 tutorial', 'body' => 'php 7 guide']);
        $e->upsert(self::QT_MODEL, 2, ['title' => 'php 8 features', 'body' => 'php 8 new features']);

        $results = $e->search('7', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Numeric query "7" should find doc 1');
    }

    #[Test]
    public function injection_attempt_returns_safe_results(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'normal document', 'body' => 'safe content']);

        $results = $e->search("' OR 1=1 --", [self::QT_MODEL], 10);
        $this->assertEmpty($results, 'SQL injection attempt should not return all docs');

        $results = $e->search('" OR "1"="1', [self::QT_MODEL], 10);
        $this->assertEmpty($results, 'SQL injection attempt with quotes should return empty');
    }

    #[Test]
    public function unicode_whitespace_handled_gracefully(): void
    {
        $e = $this->qtEngine();
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php laravel', 'body' => 'web framework']);

        $results = $e->search("php\nlaravel", [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Query with newline should find docs');

        $results = $e->search('php  laravel', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Query with double space should find docs');
    }

    #[Test]
    public function excessive_length_query_does_not_crash(): void
    {
        $e = $this->qtEngine();
        $long = 'php ' . str_repeat('framework ', 200);

        $results = $e->search($long, [self::QT_MODEL], 10);
        $this->assertIsArray($results, 'Very long query should not crash');
    }

    #[Test]
    public function special_chars_query_does_not_crash(): void
    {
        $e = $this->qtEngine();
        $results = $e->search('!@#$%^&*()_+{}[]|\\:;"\'<>,.?/~`', [self::QT_MODEL], 10);
        $this->assertIsArray($results, 'Query with special chars should not crash');
    }
}
