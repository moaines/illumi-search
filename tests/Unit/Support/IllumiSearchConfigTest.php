<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function default_driver_is_sqlite(): void
    {
        $this->assertEquals('sqlite', $this->config->driver());
    }

    #[Test]
    public function default_max_weight_is_3(): void
    {
        $this->assertEquals(3, $this->config->maxWeight());
    }

    #[Test]
    public function default_table_prefix(): void
    {
        $this->assertEquals('illumi_search_', $this->config->tablePrefix());
    }

    #[Test]
    public function default_workers_is_4(): void
    {
        $this->assertEquals(4, $this->config->workers());
    }

    #[Test]
    public function default_processor_is_unicode(): void
    {
        $this->assertEquals('unicode', $this->config->processor());
    }

    #[Test]
    public function stopwords_returns_array(): void
    {
        $sw = $this->config->stopwords();
        $this->assertIsArray($sw);
    }

    #[Test]
    public function sqlite_tokenizer_default(): void
    {
        $this->assertEquals('unicode61', $this->config->sqliteTokenizer());
    }

    #[Test]
    public function sqlite_detail_default(): void
    {
        $this->assertEquals('full', $this->config->sqliteDetail());
    }

    #[Test]
    public function sqlite_columnsize_default(): void
    {
        $this->assertEquals(1, $this->config->sqliteColumnsize());
    }

    #[Test]
    public function sqlite_busy_timeout_default(): void
    {
        $this->assertEquals(15000, $this->config->sqliteBusyTimeout());
    }

    #[Test]
    public function operators_is_array_or_null(): void
    {
        $ops = $this->config->operators();
        $this->assertTrue($ops === null || is_array($ops));
    }

    #[Test]
    public function default_processing_mode(): void
    {
        $this->assertEquals('advanced', $this->config->processingMode());
    }

    #[Test]
    public function default_max_results(): void
    {
        $this->assertEquals(50, $this->config->maxResults());
    }

    #[Test]
    public function default_max_related_values(): void
    {
        $this->assertEquals(100, $this->config->maxRelatedValues());
    }

    #[Test]
    public function default_max_search_text_length(): void
    {
        $this->assertEquals(65535, $this->config->maxSearchTextLength());
    }

    #[Test]
    public function default_indexing_mode(): void
    {
        $mode = $this->config->indexingMode();
        $this->assertContains($mode, ['queue', 'sync', 'manual']);
    }

    #[Test]
    public function default_rebuild_batch_size(): void
    {
        $this->assertIsInt($this->config->rebuildBatchSize());
    }

    #[Test]
    public function tenancy_disabled_by_default(): void
    {
        $this->assertFalse($this->config->tenancyEnabled());
    }

    #[Test]
    public function tenancy_directory_default(): void
    {
        $this->assertEquals('app/search/tenants', $this->config->tenancyDirectory());
    }

    #[Test]
    public function model_paths_returns_array(): void
    {
        $paths = $this->config->modelPaths();
        $this->assertIsArray($paths);
    }

    #[Test]
    public function queue_connection_is_nullable(): void
    {
        $qc = $this->config->queueConnection();
        $this->assertTrue($qc === null || is_string($qc));
    }

    #[Test]
    public function authorization_disabled_by_default(): void
    {
        $this->assertFalse($this->config->authorizationEnabled());
    }

    #[Test]
    public function sqlite_automerge_default(): void
    {
        $this->assertEquals(4, $this->config->sqliteAutomerge());
    }

    #[Test]
    public function sqlite_crisismerge_default(): void
    {
        $this->assertEquals(16, $this->config->sqliteCrisismerge());
    }

    #[Test]
    public function sqlite_pgsz_default(): void
    {
        $this->assertEquals(1000, $this->config->sqlitePgsz());
    }

    #[Test]
    public function sqlite_vocab_limit_default(): void
    {
        $this->assertGreaterThan(0, $this->config->sqliteVocabLimit());
    }

    #[Test]
    public function mysql_unix_socket_default(): void
    {
        $this->assertEquals('', $this->config->mysqlUnixSocket());
    }
}
