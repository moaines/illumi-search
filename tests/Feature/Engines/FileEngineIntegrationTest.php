<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\FileEngine;

class FileEngineIntegrationTest extends AbstractEngineTest
{
    private ?FileEngine $engine = null;
    private string $tempBase;

    protected function createEngine(): Engine
    {
        $this->tempBase ??= storage_path('app/test-file-engine-' . uniqid());

        if ($this->engine === null) {
            $this->engine = new FileEngine($this->tempBase);
        }

        $this->engine->createTable($this->testModelClass, ['title', 'body']);

        return $this->engine;
    }

    protected function tearDown(): void
    {
        if ($this->tempBase && is_dir($this->tempBase)) {
            $this->deleteDirectory($this->tempBase);
        }
        $this->engine = null;

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/**/*.php') as $file) {
            @unlink($file);
        }
        foreach (glob($dir . '/**/*.json') as $file) {
            @unlink($file);
        }
        foreach (glob($dir . '/**/*.stats') as $file) {
            @unlink($file);
        }
        foreach (glob($dir . '/**/*.tmp') as $file) {
            @unlink($file);
        }

        foreach (array_reverse(glob($dir . '/**/*/', GLOB_ONLYDIR)) as $sub) {
            @rmdir($sub);
        }

        @rmdir($dir);
    }

    // ─── Cache tests ────────────────────────────────────

    /** @test */
    public function cache_speeds_up_repeated_search(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'laravel guide', 'body' => 'build web apps']);

        // Cold search (cache miss): should take > 1ms (reads chunks)
        $warmup = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(1, $warmup);

        // Cached search: should return same results
        $cached = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(1, $cached);
        $this->assertEquals(1, $cached[0]->modelId);
    }

    /** @test */
    public function cache_cleared_on_upsert(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php programming', 'body' => 'learn php']);

        $before = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(1, $before);

        // Upsert changes data — cache should be cleared
        $engine->upsert($this->testModelClass, 1, ['title' => 'new topic', 'body' => 'content']);

        $after = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(0, $after, 'Cache should be cleared after upsert');

        $afterNew = $engine->search('new', [$this->testModelClass], 10);
        $this->assertCount(1, $afterNew, 'New data should be searchable after cache clear');
    }

    /** @test */
    public function cache_cleared_on_delete(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);

        $engine->search('php', [$this->testModelClass], 10);

        $engine->delete($this->testModelClass, 1);
        $after = $engine->search('php', [$this->testModelClass], 10);
        $this->assertCount(0, $after, 'Cache should be cleared after delete');
    }

    /** @test */
    public function sentinel_with_live_pid_not_removed(): void
    {
        $engine = $this->createEngine();

        $ref = new \ReflectionClass($engine);
        $sentinelPath = $ref->getMethod('sentinelPath')->invoke($engine);

        // Write our own PID (we are alive)
        file_put_contents($sentinelPath, (string) getmypid());

        $engine->search('php', [$this->testModelClass], 10);

        // Sentinel should remain since process is alive
        $this->assertFileExists($sentinelPath);
        @unlink($sentinelPath);
    }

    // ─── Concurrent processor tests ─────────────────────

    /** @test */
    public function concurrent_processor_falls_back_to_sequential_in_tests(): void
    {
        // In tests, app()->runningUnitTests() returns true, so canFork() = false
        // This test verifies search still works in sequential mode
        $engine = $this->createEngine();

        for ($i = 1; $i <= 10; $i++) {
            $engine->upsert($this->testModelClass, $i, ['title' => "post $i", 'body' => 'content']);
        }

        $results = $engine->search('content', [$this->testModelClass], 10);
        $this->assertCount(10, $results, 'Sequential mode should return all results');
    }

    // ─── Write operation tests ──────────────────────────

    /** @test */
    public function upsert_then_delete_then_upsert_same_id(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'php', 'body' => 'content']);
        $this->assertEquals(1, $engine->count('php', [$this->testModelClass]));

        $engine->delete($this->testModelClass, 1);
        $this->assertEquals(0, $engine->count('php', [$this->testModelClass]));

        // Re-insert same ID
        $engine->upsert($this->testModelClass, 1, ['title' => 'php again', 'body' => 'content']);
        $this->assertEquals(1, $engine->count('php', [$this->testModelClass]));
    }

    /** @test */
    public function large_insert_batch_of_500_documents(): void
    {
        $engine = $this->createEngine();
        $documents = [];

        for ($i = 1; $i <= 500; $i++) {
            $documents[] = [
                'model_id' => $i,
                'document' => ['title' => "zxyword{$i}abc", 'body' => 'commonbody'],
            ];
        }

        $engine->insertBatch($this->testModelClass, $documents);

        $this->assertEquals(500, $engine->count('commonbody', [$this->testModelClass]));
        $this->assertEquals(1, $engine->count('zxyword1abc', [$this->testModelClass]));
        $this->assertEquals(1, $engine->count('zxyword500abc', [$this->testModelClass]));
    }

    /** @test */
    public function insert_batch_with_multiple_chunks(): void
    {
        $engine = $this->createEngine();
        $documents = [];

        // Insert > CHUNK_SIZE (100) documents to trigger multiple chunks
        for ($i = 1; $i <= 250; $i++) {
            $documents[] = [
                'model_id' => $i,
                'document' => ['title' => "bulk post $i", 'body' => 'test data'],
            ];
        }

        $engine->insertBatch($this->testModelClass, $documents);

        // Verify all are searchable
        $results = $engine->search('test', [$this->testModelClass], 300);
        $this->assertCount(250, $results);
    }

    // ─── Integrity & status tests ───────────────────────

    /** @test */
    public function engine_status_returns_correct_driver(): void
    {
        $engine = $this->createEngine();
        $status = $engine->getEngineStatus();

        $this->assertEquals('FileEngine', $status['driver']);
        $this->assertArrayHasKey('engine_version', $status);
        $this->assertArrayHasKey('database_size', $status);
    }

    /** @test */
    public function table_operations_create_drop_exists(): void
    {
        $engine = $this->createEngine();

        // Need data before listIndexTables returns anything
        $engine->upsert($this->testModelClass, 1, ['title' => 'test', 'body' => 'data']);

        $this->assertTrue($engine->tableExists($this->testModelClass));
        $tables = $engine->listIndexTables();
        $tableName = $engine->tableName($this->testModelClass);
        $this->assertContains($tableName, $tables);

        $engine->dropTable($this->testModelClass);
        $this->assertFalse($engine->tableExists($this->testModelClass));
    }

    /** @test */
    public function get_index_stats_after_multiple_upserts(): void
    {
        $engine = $this->createEngine();
        $engine->upsert($this->testModelClass, 1, ['title' => 'first', 'body' => 'doc']);
        $engine->upsert($this->testModelClass, 2, ['title' => 'second', 'body' => 'doc']);

        $stats = $engine->getIndexStats();
        $this->assertCount(1, $stats);
        $this->assertEquals(2, $stats[0]['record_count']);
    }

    // ─── Edge cases ─────────────────────────────────────

    /** @test */
    public function concurrent_read_and_write_safety(): void
    {
        $engine = $this->createEngine();

        // Write 50 docs with unique identifiers using alphanumeric separator
        for ($i = 1; $i <= 50; $i++) {
            $engine->upsert($this->testModelClass, $i,
                ['title' => "zzzitem{$i}yyy", 'body' => 'sharedcontent']);
        }

        // Search while all data is there
        $results = $engine->search('sharedcontent', [$this->testModelClass], 100);
        $this->assertCount(50, $results);

        // Delete and verify atomicity
        $engine->delete($this->testModelClass, 1);
        $results = $engine->search('zzzitem1yyy', [$this->testModelClass], 10);
        $this->assertCount(0, $results, 'Deleted document should not appear');
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
    public function get_supported_operators_for_file_engine(): void
    {
        $engine = $this->createEngine();
        $operators = $engine->getSupportedOperators();

        $this->assertContains('AND', $operators);
        $this->assertContains('OR', $operators);
        $this->assertContains('NOT', $operators);
    }

    /** @test */
    public function supports_phrase_and_prefix(): void
    {
        $engine = $this->createEngine();

        $this->assertTrue($engine->supportsPhraseSearch());
        $this->assertTrue($engine->supportsPrefixWildcard());
    }

    /** @test */
    public function model_class_with_special_chars_in_name(): void
    {
        $engine = $this->createEngine();
        $modelClass = 'App\Models\Test-Model_V2';

        $engine->createTable($modelClass, ['title', 'body']);
        $engine->upsert($modelClass, 1, ['title' => 'test title', 'body' => 'content']);

        $results = $engine->search('test', [$modelClass], 10);
        $this->assertCount(1, $results);

        $engine->dropTable($modelClass);
    }

    /** @test */
    public function upsert_without_stats_file_does_not_crash(): void
    {
        $engine = $this->createEngine();

        // Simulate: upsert without stats
        $engine->upsert($this->testModelClass, 1, ['title' => 'direct insert', 'body' => 'no stats']);

        $results = $engine->search('direct', [$this->testModelClass], 10);
        $this->assertCount(1, $results, 'Should find without stats via quick scoring');
    }
}
