<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Illuminate\Support\Facades\DB;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MySqlEngine;

class MySqlEngineIntegrationTest extends AbstractEngineTest
{
    private ?MySqlEngine $engine = null;

    protected function createEngine(): Engine
    {
        if (! $this->mysqlAvailable()) {
            $this->markTestSkipped('MySQL connection not available.');
        }

        DB::connection(MySqlEngine::CONNECTION_NAME)->statement('DROP TABLE IF EXISTS search_index');

        $this->engine = new MySqlEngine;
        $this->engine->createTable('App\Models\Post', ['title', 'body']);

        return $this->engine;
    }

    protected function tearDown(): void
    {
        if ($this->engine && $this->mysqlAvailable()) {
            DB::connection(MySqlEngine::CONNECTION_NAME)->statement('DROP TABLE IF EXISTS search_index');
        }

        parent::tearDown();
    }

    private function mysqlAvailable(): bool
    {
        try {
            new MySqlEngine;
            DB::connection(MySqlEngine::CONNECTION_NAME)->getPdo();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    // ─── MySQL-specific tests ─────────────────────────────

    public function test_snippets_contain_mark_tags(): void
    {
        $engine = $this->createEngine();
        $engine->upsert('App\Models\Post', 1, [
            'title' => 'Data science guide',
            'body' => 'Data analysis with python and machine learning techniques for big data processing.',
        ]);

        $results = $engine->search('data', ['App\Models\Post'], 10, 0, 'advanced', true);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->summary);
        $this->assertStringContainsString('<mark>', $results[0]->summary);
    }

    public function test_paginate_via_query_builder(): void
    {
        $engine = $this->createEngine();
        for ($i = 1; $i <= 5; $i++) {
            $engine->upsert('App\Models\Post', $i, ['title' => "test post $i", 'body' => 'content']);
        }

        $results = $engine->search('test', ['App\Models\Post'], 2, 0);
        $total = $results->first()->totalCount ?? $engine->count('test', ['App\Models\Post']);

        $this->assertCount(2, $results);
        $this->assertEquals(5, $total);
    }

    public function test_spellcheck_suggestions(): void
    {
        $engine = $this->createEngine();

        $engine->upsert('App\Models\Post', 1, [
            'title' => 'laravel',
            'body' => 'php framework',
        ]);
        $engine->upsert('App\Models\Post', 2, [
            'title' => 'laravel package',
            'body' => 'illumi search module',
        ]);

        $suggestions = $engine->suggest('laravell');
        $this->assertNotEmpty($suggestions);
        $this->assertContains('laravel', $suggestions);

        $suggestions = $engine->suggest('framwork');
        $this->assertContains('framework', $suggestions);
    }

    public function test_rebuild_vocab_from_scratch(): void
    {
        $engine = $this->createEngine();

        $engine->upsert('App\Models\Post', 1, [
            'title' => 'php framework',
            'body' => 'laravel symfony',
        ]);
        $engine->upsert('App\Models\Post', 2, [
            'title' => 'data science',
            'body' => 'python machine learning',
        ]);

        // rebuildVocabFromScratch should repopulate the vocab table
        $engine->rebuildVocabFromScratch();

        // After rebuild, suggest should still work
        $suggestions = $engine->suggest('framwork');
        $this->assertNotEmpty($suggestions, 'Suggest should still return results after rebuild');
        $this->assertContains('framework', $suggestions);

        $suggestions = $engine->suggest('scienc');
        $this->assertContains('science', $suggestions);
    }
}
