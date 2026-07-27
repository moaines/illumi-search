<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\PgsqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class MaxDocumentsTest extends TestCase
{
    private const QT_MODEL = 'App\Models\BenchmarkPost';
    private const QT_COLUMNS = ['title', 'body'];
    private const MAX = 3;

    protected function setUp(): void
    {
        parent::setUp();
        config(['illumi-search.indexing.max_documents_per_model' => self::MAX]);
    }

    /** @return array<string, array{Engine}> */
    public static function engineProvider(): array
    {
        return ['SQLite' => ['sqlite']];
    }

    private function createEngine(string $type): Engine
    {
        if ($type === 'sqlite') {
            $path = storage_path('app/illumi-search-maxdocs.sqlite');
            @unlink($path);
            $engine = new SqliteEngine(databasePath: $path);
            $engine->dropTable(self::QT_MODEL);
            $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);

            return $engine;
        }

        throw new \InvalidArgumentException("Unknown engine: $type");
    }

    protected function tearDown(): void
    {
        $path = storage_path('app/illumi-search-maxdocs.sqlite');
        @unlink($path);
        parent::tearDown();
    }

    #[DataProvider('engineProvider')]
    public function test_prunes_old_docs_beyond_limit(string $type): void
    {
        $e = $this->createEngine($type);

        // Insert 5 docs in a batch — triggers pruneExcessDocuments
        $docs = [];
        for ($i = 1; $i <= 5; $i++) {
            $docs[] = [
                'model_id' => $i,
                'document' => ['title' => "doc {$i}", 'body' => "body {$i}"],
            ];
        }
        $e->insertBatch(self::QT_MODEL, $docs);

        $results = $e->search('doc', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertCount(self::MAX, $results, 'Only ' . self::MAX . ' most recent docs should remain');
        $this->assertContains(5, $ids, 'Doc 5 (newest) must be kept');
        $this->assertContains(4, $ids, 'Doc 4 must be kept');
        $this->assertContains(3, $ids, 'Doc 3 must be kept');
        $this->assertNotContains(1, $ids, 'Doc 1 (oldest) must be pruned');
        $this->assertNotContains(2, $ids, 'Doc 2 (oldest) must be pruned');
    }

    #[DataProvider('engineProvider')]
    public function test_does_not_prune_within_limit(string $type): void
    {
        $e = $this->createEngine($type);

        $docs = [];
        for ($i = 1; $i <= self::MAX; $i++) {
            $docs[] = [
                'model_id' => $i,
                'document' => ['title' => "doc {$i}", 'body' => "body {$i}"],
            ];
        }
        $e->insertBatch(self::QT_MODEL, $docs);

        $results = $e->search('doc', [self::QT_MODEL], 10);
        $this->assertCount(self::MAX, $results, 'All docs within limit should remain');
    }

    #[DataProvider('engineProvider')]
    public function test_unlimited_keeps_all(string $type): void
    {
        config(['illumi-search.indexing.max_documents_per_model' => 0]);
        $e = $this->createEngine($type);

        $docs = [];
        for ($i = 1; $i <= self::MAX + 2; $i++) {
            $docs[] = [
                'model_id' => $i,
                'document' => ['title' => "doc {$i}", 'body' => "body {$i}"],
            ];
        }
        $e->insertBatch(self::QT_MODEL, $docs);

        $results = $e->search('doc', [self::QT_MODEL], 10);
        $this->assertCount(self::MAX + 2, $results, 'Unlimited (0) should keep all docs');
    }

    #[DataProvider('engineProvider')]
    public function test_prune_command_works(string $type): void
    {
        $e = $this->createEngine($type);

        $docs = [];
        for ($i = 1; $i <= self::MAX + 2; $i++) {
            $docs[] = [
                'model_id' => $i,
                'document' => ['title' => "doc {$i}", 'body' => "body {$i}"],
            ];
        }
        $e->insertBatch(self::QT_MODEL, $docs);

        if (method_exists($e, 'pruneExcessDocuments')) {
            $e->pruneExcessDocuments(self::QT_MODEL);
        }

        $results = $e->search('doc', [self::QT_MODEL], 10);
        $ids = array_map(fn ($r) => $r->modelId, $results);

        $this->assertCount(self::MAX, $results, 'Manual prune should keep only N most recent');
        $this->assertContains(5, $ids);
    }
}
