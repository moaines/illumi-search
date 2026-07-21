<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\IndexManager;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Book;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;
use Moaines\IllumiSearch\Tests\TestCase;

class IndexManagerTest extends TestCase
{
    private IndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['illumi-search.model_paths' => [__DIR__ . '/../TestSupport/Models']]);

        $this->manager = new IndexManager(
            $this->app->make(Engine::class),
            app(TextProcessor::class),
        );
    }

    public function test_discover_models_returns_searchable_classes(): void
    {
        $models = $this->manager->discoverModels();

        $this->assertContains(Post::class, $models);
    }

    public function test_discover_models_caches_result(): void
    {
        $first = $this->manager->discoverModels();
        $second = $this->manager->discoverModels();

        $this->assertSame($first, $second);
    }

    public function test_discover_models_with_refresh_reruns(): void
    {
        $first = $this->manager->discoverModels();
        $second = $this->manager->discoverModels(refresh: true);

        $this->assertNotSame($first, $second);
    }

    public function test_check_schema_returns_array(): void
    {
        $result = $this->manager->checkSchema();

        $this->assertIsArray($result);
    }

    public function test_check_schema_does_not_report_drift_for_dot_notation(): void
    {
        $engine = $this->app->make(Engine::class);

        $engine->createTable(Book::class, ['title', 'body', 'author_name', 'comments_body', 'fullname']);

        $engine->upsert(Book::class, 1, [
            'title' => 'Test',
            'body' => 'Test',
            'author_name' => 'Test',
            'comments_body' => 'Test',
            'fullname' => 'Test',
        ]);

        $checks = $this->manager->checkSchema();

        $bookCheck = collect($checks)->firstWhere('model', Book::class);
        $this->assertNotNull($bookCheck, 'Book should be discovered');
        $this->assertNotEquals('drift', $bookCheck['status'], 'Dot-notation columns should not cause false DRIFT');
    }
}
