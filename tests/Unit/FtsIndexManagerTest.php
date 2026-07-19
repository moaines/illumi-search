<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\SqliteFtsEngine;
use Moaines\IllumiSearch\FtsIndexManager;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;
use Moaines\IllumiSearch\Tests\TestCase;

class FtsIndexManagerTest extends TestCase
{
    private FtsIndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fts.model_paths' => [__DIR__ . '/../TestSupport/Models']]);

        $this->manager = new FtsIndexManager(
            $this->app->make(FtsEngine::class),
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
}
