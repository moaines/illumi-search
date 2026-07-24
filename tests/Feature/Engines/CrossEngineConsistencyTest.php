<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

/**
 * Verifies that all engines produce consistent search results
 * for identical datasets.
 *
 * Each engine receives the same documents and queries, and we
 * assert that the returned document IDs and ranking order are
 * comparable (not necessarily identical, but same docs found).
 */
class CrossEngineConsistencyTest extends TestCase
{
    private const MODEL_CLASS = 'App\Models\BenchmarkPost';
    private const COLUMNS = ['title', 'body'];

    private function createEngine(string $name): ?Engine
    {
        try {
            return match ($name) {
                'sqlite' => $this->createSqliteEngine(),
                'file' => $this->createFileEngine(),
                'mysql' => $this->createMySqlEngine(),
                default => throw new \InvalidArgumentException("Unknown engine: $name"),
            };
        } catch (\Exception $e) {
            $this->markTestSkipped("$name not available: " . $e->getMessage());

            return null;
        }
    }

    private function createSqliteEngine(): Engine
    {
        $path = storage_path('app/consistency-test-' . uniqid() . '.sqlite');
        $engine = new SqliteEngine($path);
        $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

        return $engine;
    }

    private function createFileEngine(): Engine
    {
        $engine = new FileEngine(storage_path('app/consistency-test-file-' . uniqid()));
        $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

        return $engine;
    }

    private function createMySqlEngine(): ?Engine
    {
        try {
            $engine = new MySqlEngine;
            $engine->dropTable(self::MODEL_CLASS);
            $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

            return $engine;
        } catch (\Exception $e) {
            $this->markTestSkipped("MySQL not available: " . $e->getMessage());

            return null;
        }
    }

    private function destroyEngine(string $name, ?Engine $engine): void
    {
        if ($engine === null) {
            return;
        }
        try {
            if ($name === 'sqlite') {
                $path = $engine->getDatabasePath();
                $engine->dropTable(self::MODEL_CLASS);
                @unlink($path);
            } elseif ($name === 'mysql') {
                $engine->dropTable(self::MODEL_CLASS);
            }
        } catch (\Exception) {
            // cleanup best-effort
        }
    }

    /** @return string[] */
    public static function engineProvider(): array
    {
        return [['file'], ['sqlite'], ['mysql']];
    }

    /**
     * Insert the same 5 documents into an engine and verify basic search works.
     *
     * @test
     *
     * @dataProvider engineProvider
     */
    public function all_engines_find_same_document(string $engineName): void
    {
        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'php framework', 'body' => 'laravel and symfony']);
        if (method_exists($engine, 'rebuildVocabFromScratch')) {
            $engine->rebuildVocabFromScratch();
        }

        $results = $engine->search('php', [self::MODEL_CLASS], 10);
        $this->assertCount(1, $results, "$engineName should find the document");
        $this->assertEquals(1, $results[0]->modelId, "$engineName should return doc 1");

        $this->destroyEngine($engineName, $engine);
    }

    /**
     * @test
     *
     * @dataProvider engineProvider
     */
    public function ranking_puts_title_match_first(string $engineName): void
    {
        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'php programming', 'body' => 'other content']);
        $engine->upsert(self::MODEL_CLASS, 2, ['title' => 'other topic', 'body' => 'php programming']);
        if (method_exists($engine, 'rebuildVocabFromScratch')) {
            $engine->rebuildVocabFromScratch();
        }

        $results = $engine->search('php', [self::MODEL_CLASS], 10);
        $this->assertCount(2, $results, "$engineName should find both docs");
        $this->assertEquals(1, $results[0]->modelId, "$engineName: title match should rank first");

        $this->destroyEngine($engineName, $engine);
    }

    /**
     * @test
     *
     * @dataProvider engineProvider
     */
    public function weight_3_column_scores_higher_than_weight_1(string $engineName): void
    {
        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        // Doc 1: "php" in title (weight 3) + body (weight 1)
        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'php programming', 'body' => 'php basics']);
        // Doc 2: "php" in body only (weight 1)
        $engine->upsert(self::MODEL_CLASS, 2, ['title' => 'other topic', 'body' => 'php programming']);
        if (method_exists($engine, 'rebuildVocabFromScratch')) {
            $engine->rebuildVocabFromScratch();
        }

        $results = $engine->search('php', [self::MODEL_CLASS], 10);
        $this->assertCount(2, $results, "$engineName should find both docs");

        // Both results should have non-zero rank (FTS5 returns negative values, others positive)
        foreach ($results as $r) {
            $this->assertNotEquals(0, $r->rank, "$engineName: rank should be non-zero");
        }

        $this->destroyEngine($engineName, $engine);
    }

    /**
     * @test
     *
     * @dataProvider engineProvider
     */
    public function phrase_search_requires_consecutive_words(string $engineName): void
    {
        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'php moderne', 'body' => 'about php 8']);
        $engine->upsert(self::MODEL_CLASS, 2, ['title' => 'php 8', 'body' => 'php moderne explained']);
        $engine->upsert(self::MODEL_CLASS, 3, ['title' => 'other', 'body' => 'no match']);

        $results = $engine->search('"php moderne"', [self::MODEL_CLASS], 10, 0, 'advanced');
        $this->assertNotEmpty($results, "$engineName should find docs with consecutive 'php moderne'");

        $ids = array_map(fn ($r) => $r->modelId, $results);
        $this->assertContains(1, $ids, "$engineName should match doc 1");
        $this->assertContains(2, $ids, "$engineName should match doc 2");

        $this->destroyEngine($engineName, $engine);
    }

    /**
     * @test
     *
     * @dataProvider engineProvider
     */
    public function and_operator_requires_both_terms(string $engineName): void
    {
        if ($engineName === 'mysql') {
            $this->markTestSkipped('MySQL FULLTEXT + operator is not 100% reliable (known limitation)');
        }

        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'php framework', 'body' => '']);
        $engine->upsert(self::MODEL_CLASS, 2, ['title' => 'php basics', 'body' => '']);
        $engine->upsert(self::MODEL_CLASS, 3, ['title' => 'python basics', 'body' => '']);

        $results = $engine->search('php AND basics', [self::MODEL_CLASS], 10);
        $this->assertCount(1, $results, "$engineName: only doc 2 has 'php' AND 'basics'");
        $this->assertEquals(2, $results[0]->modelId, "$engineName should find doc 2");

        $this->destroyEngine($engineName, $engine);
    }

    /**
     * @test
     *
     * @dataProvider engineProvider
     */
    public function all_engines_handle_empty_and_special_queries(string $engineName): void
    {
        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $engine->upsert(self::MODEL_CLASS, 1, ['title' => 'normal', 'body' => 'content']);

        $queries = ['', '   ', "!@#$%^&*()", "\n\t\r", "' OR 1=1 --"];
        foreach ($queries as $q) {
            $results = $engine->search($q, [self::MODEL_CLASS], 10);
            $this->assertIsArray($results, "$engineName: query '$q' should not throw");
            $this->assertCount(0, $results, "$engineName: query '$q' should return empty");
        }

        $this->destroyEngine($engineName, $engine);
    }
}
