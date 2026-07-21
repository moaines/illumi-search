<?php

namespace Moaines\IllumiSearch\Tests\Unit\Debug;

use Moaines\IllumiSearch\Debug\IllumiSearchCollector;
use Moaines\IllumiSearch\Tests\TestCase;

class IllumiSearchCollectorTest extends TestCase
{
    public function test_collector_returns_zero_count_when_no_queries(): void
    {
        $collector = new IllumiSearchCollector;
        $result = $collector->collect();

        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['data']);
    }

    public function test_collector_records_query_with_results(): void
    {
        $collector = new IllumiSearchCollector;
        $collector->addQuery(
            matchQuery: 'php AND laravel',
            table: 'idx_app_models_post',
            modelClass: 'App\Models\Post',
            mode: 'advanced',
            resultCount: 12,
            durationMs: 64.09,
            topScores: [-1.23, -3.45, -5.67],
        );

        $result = $collector->collect();

        $this->assertEquals(1, $result['count']);
        $this->assertCount(1, $result['data']);
        $this->assertStringContainsString("MATCH 'php AND laravel'", $result['data'][0]);
        $this->assertStringContainsString('Post (12 results', $result['data'][0]);
        $this->assertStringContainsString('BM25:', $result['data'][0]);
    }

    public function test_collector_records_query_without_scores(): void
    {
        $collector = new IllumiSearchCollector;
        $collector->addQuery(
            matchQuery: 'test',
            table: 'idx_app_models_post',
            modelClass: 'App\Models\Post',
            mode: 'basic',
            resultCount: 0,
            durationMs: 0.5,
        );

        $result = $collector->collect();

        $this->assertEquals(1, $result['count']);
        $this->assertStringContainsString('mode: basic', $result['data'][0]);
        $this->assertStringNotContainsString('BM25:', $result['data'][0]);
    }

    public function test_collector_displays_engine_info(): void
    {
        $collector = new IllumiSearchCollector;
        $collector->setEngineInfo([
            'version' => 'SQLite 3.52.0 | FTS5',
            'tokenizer' => 'unicode61',
            'indexed_records' => 9437,
            'fts5_available' => true,
        ]);

        $result = $collector->collect();

        $this->assertEquals(0, $result['count']);
        $this->assertStringContainsString('SQLite 3.52.0', $result['data'][0]);
        $this->assertStringContainsString('unicode61', $result['data'][1]);
        $this->assertStringContainsString('9,437', $result['data'][2]);
    }

    public function test_collector_name_and_widgets(): void
    {
        $collector = new IllumiSearchCollector;

        $this->assertEquals('illumi-search', $collector->getName());

        $widgets = $collector->getWidgets();
        $this->assertArrayHasKey('illumi-search', $widgets);
        $this->assertArrayHasKey('illumi-search:badge', $widgets);
        $this->assertEquals('PhpDebugBar.Widgets.ListWidget', $widgets['illumi-search']['widget']);
        $this->assertEquals('illumi-search.data', $widgets['illumi-search']['map']);
    }
}
