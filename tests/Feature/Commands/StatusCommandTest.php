<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\IndexManager;
use Moaines\IllumiSearch\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    public function test_status_no_database(): void
    {
        $this->artisan('illumi-search:status')
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();
    }

    public function test_status_shows_index_stats(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $this->artisan('illumi-search:status')
            ->expectsOutputToContain('App\Models\Post')
            ->assertSuccessful();
    }

    public function test_schema_config_is_stored_and_retrievable(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);

        $schema = ['version' => '1.0', 'models' => [['class' => 'App\Models\Post', 'columns' => [], 'records' => 5]]];
        $engine->setConfig('searchable_schema', $schema);
        $engine->setConfig('rebuild_completed_at', now()->toIso8601String());
        $engine->setConfig('rebuild_duration_ms', 1000);
        $engine->setConfig('rebuild_total_records', 42);

        $this->assertEquals($schema, $engine->getConfig('searchable_schema'));
        $this->assertNotNull($engine->getConfig('rebuild_completed_at'));
        $this->assertEquals(1000, $engine->getConfig('rebuild_duration_ms'));
        $this->assertEquals(42, $engine->getConfig('rebuild_total_records'));
    }
}
