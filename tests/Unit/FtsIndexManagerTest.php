<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\FtsIndexManager;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Tests\TestCase;

class FtsIndexManagerTest extends TestCase
{
    private FtsEngine $engine;
    private FtsIndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fts.model_paths' => [__DIR__ . '/../TestSupport/Models']]);

        $this->engine = $this->createMock(FtsEngine::class);
        $this->manager = new FtsIndexManager(
            $this->engine,
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

    public function test_check_schema_reports_missing_tables(): void
    {
        $this->engine->method('tableExists')->willReturn(false);
        $this->engine->method('getIndexedModelClasses')->willReturn([]);

        $result = $this->manager->checkSchema();

        $this->assertIsArray($result);
    }
}
