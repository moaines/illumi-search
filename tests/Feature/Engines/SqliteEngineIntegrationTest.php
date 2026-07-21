<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;

class SqliteEngineIntegrationTest extends AbstractEngineTest
{
    protected function createEngine(): Engine
    {
        $engine = $this->app->make(Engine::class);
        $engine->dropTable('App\Models\Post');
        $engine->createTable('App\Models\Post', ['title', 'body']);

        return $engine;
    }
}
