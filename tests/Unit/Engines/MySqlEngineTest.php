<?php

namespace Moaines\IllumiSearch\Tests\Unit\Engines;

use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class MySqlEngineTest extends TestCase
{
    public function test_engine_specifics(): void
    {
        $engine = new MySqlEngine;

        $this->assertFalse($engine->isFts5Available());
        $this->assertNull($engine->getPragma('busy_timeout'));
        $this->assertSame('illumi-search-mysql', $engine->getDatabasePath());
    }

    public function test_query_vocab_returns_empty(): void
    {
        $engine = new MySqlEngine;

        $this->assertEmpty($engine->queryVocab('App\Models\Post', 'term', 3, 5));
    }

    public function test_vacuum_is_noop(): void
    {
        $engine = new MySqlEngine;

        $engine->vacuum();
        $this->assertTrue(true);
    }

    public function test_table_name_is_always_search_index(): void
    {
        $engine = new MySqlEngine;

        $this->assertSame('search_index', $engine->tableName('App\Models\Post'));
        $this->assertSame('search_index', $engine->tableName('App\Models\Book'));
    }
}
