<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Tests\TestCase;

class OptimizeCommandTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->app->make(Engine::class);
    }

    public function test_optimize_succeeds_with_existing_database(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $results = $this->engine->optimize();

        $this->assertArrayHasKey('vacuum', $results);
        $this->assertArrayHasKey('tables_optimized', $results);
        $this->assertGreaterThanOrEqual(1, $results['tables_optimized']);
    }

    public function test_optimize_handles_empty_database(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);

        $results = $this->engine->optimize();

        $this->assertGreaterThanOrEqual(1, $results['tables_optimized']);
    }

    public function test_command_outputs_size_info(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        for ($i = 1; $i <= 10; $i++) {
            $this->engine->upsert('App\Models\Post', $i, ['title' => "post $i", 'body' => 'content']);
        }

        $this->artisan('illumi-search:optimize')
            ->expectsOutputToContain('Engine:')
            ->expectsOutputToContain('optimization')
            ->assertSuccessful();
    }
}
