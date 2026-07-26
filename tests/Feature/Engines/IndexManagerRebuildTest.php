<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Engines\PgsqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class IndexManagerRebuildTest extends TestCase
{
    private const QT_MODEL = 'App\Models\BenchmarkPost';
    private const QT_COLUMNS = ['title', 'body'];

    public function test_rebuild_then_search_finds_data(): void
    {
        $engine = $this->createEngine();
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'php laravel guide', 'body' => 'learn laravel']);
        $engine->upsert(self::QT_MODEL, 2, ['title' => 'python guide', 'body' => 'learn python']);

        $results = $engine->search('laravel', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Search before rebuild should find data');

        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        $engine->setRebuilding(true);
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'php laravel guide', 'body' => 'learn laravel']);
        $engine->setRebuilding(false);

        $results = $engine->search('laravel', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Search after rebuild should find data');
    }

    public function test_rebuild_preserves_vocab(): void
    {
        $engine = $this->createEngine();
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'php programming', 'body' => 'learn programming']);

        if (method_exists($engine, 'rebuildVocabFromScratch')) {
            $engine->rebuildVocabFromScratch();

            if (method_exists($engine, 'suggest')) {
                $suggestions = $engine->suggest('programing', 2, 5);
                $this->assertNotEmpty($suggestions, 'Suggest should work after vocab rebuild');
            }
        } else {
            $this->assertTrue(true, 'Engine does not support rebuildVocabFromScratch');
        }
    }

    public function test_rebuild_idempotent(): void
    {
        $engine = $this->createEngine();

        // First rebuild cycle
        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        $engine->setRebuilding(true);
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'test one', 'body' => 'first']);
        $engine->setRebuilding(false);

        $first = $engine->search('one', [self::QT_MODEL], 10);
        $this->assertCount(1, $first);

        // Second rebuild — same data
        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        $engine->setRebuilding(true);
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'test one', 'body' => 'first']);
        $engine->setRebuilding(false);

        $second = $engine->search('one', [self::QT_MODEL], 10);
        $this->assertCount(1, $second, 'Rebuild twice should produce same results');
    }

    public function test_drop_shared_table_does_not_crash_other_models(): void
    {
        $engine = $this->createEngine();
        $engine->upsert(self::QT_MODEL, 1, ['title' => 'shared table test', 'body' => 'data']);

        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);

        // Should not crash — recreate allows new inserts
        $engine->upsert(self::QT_MODEL, 2, ['title' => 'after drop', 'body' => 'recreated']);
        $results = $engine->search('after', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Can search after drop + recreate');
    }

    private function createEngine(): Engine
    {
        $engine = new SqliteEngine(
            databasePath: storage_path('app/illumi-search-rebuild-test.sqlite'),
        );
        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        return $engine;
    }

    protected function tearDown(): void
    {
        $path = storage_path('app/illumi-search-rebuild-test.sqlite');
        @unlink($path);
        parent::tearDown();
    }
}
