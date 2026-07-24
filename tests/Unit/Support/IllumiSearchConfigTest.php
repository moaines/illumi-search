<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\IllumiSearchConfig;
use Moaines\IllumiSearch\Tests\TestCase;

class IllumiSearchConfigTest extends TestCase
{
    private IllumiSearchConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new IllumiSearchConfig;
    }

    /** @test */
    public function default_driver_is_sqlite(): void
    {
        $this->assertEquals('sqlite', $this->config->driver());
    }

    /** @test */
    public function default_max_weight_is_3(): void
    {
        $this->assertEquals(3, $this->config->maxWeight());
    }

    /** @test */
    public function default_table_prefix(): void
    {
        $this->assertEquals('illumi_search_', $this->config->tablePrefix());
    }

    /** @test */
    public function default_workers_is_4(): void
    {
        $this->assertEquals(4, $this->config->workers());
    }

    /** @test */
    public function default_processor_is_unicode(): void
    {
        $this->assertEquals('unicode', $this->config->processor());
    }

    /** @test */
    public function stopwords_returns_array(): void
    {
        $sw = $this->config->stopwords();
        $this->assertIsArray($sw);
    }

    /** @test */
    public function sqlite_tokenizer_default(): void
    {
        $this->assertEquals('unicode61', $this->config->sqliteTokenizer());
    }

    /** @test */
    public function sqlite_detail_default(): void
    {
        $this->assertEquals('full', $this->config->sqliteDetail());
    }

    /** @test */
    public function sqlite_columnsize_default(): void
    {
        $this->assertEquals(1, $this->config->sqliteColumnsize());
    }

    /** @test */
    public function sqlite_busy_timeout_default(): void
    {
        $this->assertEquals(15000, $this->config->sqliteBusyTimeout());
    }

    /** @test */
    public function operators_is_array_or_null(): void
    {
        $ops = $this->config->operators();
        $this->assertTrue($ops === null || is_array($ops));
    }
}
