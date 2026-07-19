<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Tests\TestCase;

class RebuildCommandTest extends TestCase
{
    public function test_rebuild_no_model_without_force_prompts_confirmation(): void
    {
        $this->artisan('illumi-search:rebuild')
            ->expectsConfirmation('This will rebuild ALL indexed models. Continue?', 'no')
            ->expectsOutput('Rebuild cancelled.')
            ->assertSuccessful();
    }

    public function test_rebuild_with_force_succeeds(): void
    {
        $this->artisan('illumi-search:rebuild --force')
            ->expectsOutput('Rebuild complete.')
            ->assertSuccessful();
    }

    public function test_rebuild_cleans_orphan_index_tables(): void
    {
        $engine = app(Engine::class);

        // Create an orphan FTS table for a class that does NOT use Searchable
        $orphanModelClass = 'App\\Models\\DeletedModel';
        $engine->createTable($orphanModelClass, ['title', 'body']);
        $orphanTable = $engine->tableName($orphanModelClass);

        $this->assertContains($orphanTable, $engine->listIndexTables());

        // Rebuild — only processes models with the Searchable trait, should clean orphan
        $this->artisan('illumi-search:rebuild --force')
            ->assertSuccessful();

        // Orphan table should be removed after rebuild
        $tablesAfter = $engine->listIndexTables();
        $this->assertNotContains($orphanTable, $tablesAfter);
    }

    public function test_rebuild_progress_callback_called(): void
    {
        \Illuminate\Support\Facades\Schema::create('posts', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::withoutEvents(fn () => \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::forceCreate([
            'title' => 'Test',
            'body' => 'Content',
        ]));

        $manager = app(\Moaines\IllumiSearch\IndexManager::class);

        $startedModels = [];
        $manager->rebuild(
            modelClasses: [\Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class],
            progress: function (string $event, ...$args) use (&$startedModels) {
                if ($event === 'startModel') {
                    $startedModels[] = $args[0];
                }
            },
        );

        $this->assertNotEmpty($startedModels);
    }
}
