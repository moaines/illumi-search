<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class DriverSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['illumi-search.driver' => 'sqlite']);
    }

    public function test_sqlite_engine_is_default(): void
    {
        $engine = app(Engine::class);

        $this->assertFalse($engine instanceof MySqlEngine, 'Default engine should be SqliteEngine');
    }

    public function test_mysql_engine_can_be_instantiated(): void
    {
        $engine = new MySqlEngine;

        $this->assertInstanceOf(MySqlEngine::class, $engine);
        $this->assertFalse($engine->isFts5Available());
        $this->assertSame('illumi-search-mysql', $engine->getDatabasePath());
    }
}
