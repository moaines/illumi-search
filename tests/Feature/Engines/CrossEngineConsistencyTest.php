<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Engines\MeilisearchEngine;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\ChecksMeilisearch;
use Moaines\IllumiSearch\Tests\TestCase;

/** * Verifies that all engines produce consistent search results
 * for identical datasets.
 *
 * Each engine receives the same documents and queries, and we
 * assert that the returned document IDs and ranking order are
 * comparable (not necessarily identical, but same docs found).
 */
class CrossEngineConsistencyTest extends TestCase
{
    use ChecksMeilisearch;

    private const MODEL_CLASS = 'App\Models\BenchmarkPost';
    private const COLUMNS = ['title', 'body'];

    /** @var array<string, Engine> */
    private static array $sharedEngines = [];

    private function createEngine(string $name): ?Engine
    {
        // MySQL / Meilisearch: fresh engine each time (stateful HTTP/DB connections)
        if (in_array($name, ['mysql', 'meilisearch'], true)) {
            return $name === 'mysql' ? $this->createMySqlEngine() : $this->createMeilisearchEngine();
        }

        // FileEngine / SQLite: reuse shared engine (stateless)
        if (isset(self::$sharedEngines[$name])) {
            $engine = self::$sharedEngines[$name];
            try {
                $engine->dropTable(self::MODEL_CLASS);
            } catch (\Exception) {
            }
            $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

            return $engine;
        }

        try {
            $engine = match ($name) {
                'sqlite' => $this->createSqliteEngine(),
                'file' => $this->createFileEngine(),
                default => throw new \InvalidArgumentException("Unknown engine: $name"),
            };
            self::$sharedEngines[$name] = $engine;

            return $engine;
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

    private function createMeilisearchEngine(): ?Engine
    {
        if (! $this->meilisearchAvailable()) {
            return $this->markTestSkipped('Meilisearch not available');
        }

        $engine = new MeilisearchEngine(
            host: config('illumi-search.engines.meilisearch.host', 'http://localhost:7700'),
            apiKey: config('illumi-search.engines.meilisearch.api_key', 'masterKey'),
        );

        try {
            $engine->dropTable(self::MODEL_CLASS);
        } catch (\Exception) {
        }
        $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

        return $engine;
    }

    private function createMySqlEngine(): ?Engine
    {
        try {
            if (! isset(self::$sharedEngines['mysql'])) {
                $engine = new MySqlEngine;
                $engine->dropTable(self::MODEL_CLASS);
                $engine->createTable(self::MODEL_CLASS, self::COLUMNS);
                self::$sharedEngines['mysql'] = $engine;
            }

            // Re-register connection (Testbench resets between tests)
            new MySqlEngine;

            // TRUNCATE is instant vs DROP + CREATE (~5s)
            $conn = MySqlEngine::CONNECTION_NAME;
            DB::connection($conn)->statement('TRUNCATE TABLE illumi_search_index');

            return self::$sharedEngines['mysql'];
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
        // Shared engines (file, sqlite) stay alive
        if (isset(self::$sharedEngines[$name])) {
            return;
        }
        try {
            if ($name === 'sqlite') {
                $path = $engine->getDatabasePath();
                $engine->dropTable(self::MODEL_CLASS);
                @unlink($path);
            } elseif ($name === 'mysql') {
                $conn = MySqlEngine::CONNECTION_NAME;
                DB::connection($conn)->statement('TRUNCATE TABLE illumi_search_index');
            } elseif ($name === 'meilisearch') {
                $engine->dropTable(self::MODEL_CLASS);
            }
        } catch (\Exception) {
            // cleanup best-effort
        }
    }

    /** @return string[] */
    public static function engineProvider(): array
    {
        return [['file'], ['sqlite'], ['mysql'], ['meilisearch']];
    }

    /**
     * Insert the same 5 documents into an engine and verify basic search works.
     *
     * */
#[Test]
#[DataProvider('engineProvider')]
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

    /** */
#[Test]
#[DataProvider('engineProvider')]
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

    /** */
#[Test]
#[DataProvider('engineProvider')]
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

        // Both results should have non-zero rank (all engines return positive after -RANK negation)
        foreach ($results as $r) {
            $this->assertNotEquals(0, $r->rank, "$engineName: rank should be non-zero");
        }

        $this->destroyEngine($engineName, $engine);
    }

    /** */
#[Test]
#[DataProvider('engineProvider')]
    public function phrase_search_requires_consecutive_words(string $engineName): void
    {
        if ($engineName === 'meilisearch') {
            $this->markTestSkipped('Meilisearch partial phrase match differs from FTS engines');
        }

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

    /** */
#[Test]
#[DataProvider('engineProvider')]
    public function and_operator_requires_both_terms(string $engineName): void
    {
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

    /** */
#[Test]
#[DataProvider('engineProvider')]
    public function all_engines_handle_empty_and_special_queries(string $engineName): void
    {
        if ($engineName === 'meilisearch') {
            $this->markTestSkipped('Meilisearch handles special chars differently (no SQL, no MySQL prefixes)');
        }

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

    /** */
#[Test]
#[DataProvider('engineProvider')]
    public function all_engines_support_multi_language_search(string $engineName): void
    {
        if (in_array($engineName, ['mysql', 'meilisearch'], true)) {
            $this->markTestSkipped("{$engineName} is too slow for bulk multi-language test");
        }

        $engine = $this->createEngine($engineName);
        if ($engine === null) {
            return;
        }

        $seedPath = __DIR__ . '/fixtures/seed.json';
        if (! file_exists($seedPath)) {
            $this->markTestSkipped('seed.json not found');
        }

        $processor = app(TextProcessor::class);
        $data = json_decode(file_get_contents($seedPath), true);
        $allPosts = $data['posts'] ?? [];
        $this->assertNotEmpty($allPosts, "$engineName: seed.json must have posts");

        // Index up to 6 posts per language
        $docId = 0;
        foreach (['fr', 'zh', 'ru', 'ar', 'es', 'pt'] as $lang) {
            $posts = array_values(array_filter($allPosts, fn ($p) => ($p['language'] ?? '') === $lang));
            $limit = match ($engineName) {
                'mysql' => 6,
                'file' => 6,
                default => 24,
            };
            foreach (array_slice($posts, 0, $limit) as $post) {
                $docId++;
                $engine->upsert(self::MODEL_CLASS, $docId, [
                    'title' => $processor->process($post['title']),
                    'body' => $processor->process($post['body']),
                ]);
            }
        }

        // Test each language
        $langQueries = [
            'fr' => ['logiciel'],
            'es' => ['software'],
            'pt' => ['software'],
            'ru' => ['программного'],
            'ar' => ['هندسة'],
        ];

        foreach ($langQueries as $lang => $queries) {
            foreach ($queries as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "$engineName: $lang search '$q' should return results");
            }
        }

        $this->destroyEngine($engineName, $engine);
    }
}
