<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class DiagnosticsTest extends TestCase
{
    protected Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(Engine::class);
        $this->engine->createTable(Post::class, ['title', 'body']);
    }

    public function test_get_engine_version_returns_string(): void
    {
        $version = $this->engine->getEngineVersion();

        $this->assertStringContainsString('SQLite', $version);
        $this->assertStringContainsString('FTS5', $version);
    }

    public function test_get_pragma_journal_mode(): void
    {
        $mode = $this->engine->getPragma('journal_mode');

        $this->assertNotEmpty($mode);
    }

    public function test_get_pragma_cache_size(): void
    {
        $size = $this->engine->getPragma('cache_size');

        $this->assertIsInt($size);
    }

    public function test_get_pragma_unsafe_throws(): void
    {
        $this->expectException(IllumiSearchException::class);

        $this->engine->getPragma('writable_schema');
    }

    public function test_full_integrity_check_passes(): void
    {
        $result = $this->engine->fullIntegrityCheck();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['passed'], 'Integrity check failed: ' . implode('; ', $result['errors']));
        $this->assertEmpty($result['errors']);
    }

    public function test_get_config_default(): void
    {
        $value = $this->engine->getConfig('nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    public function test_set_and_get_config(): void
    {
        $key = 'test_last_run';
        $value = '2026-07-12T12:00:00+00:00';

        $this->engine->setConfig($key, $value);
        $retrieved = $this->engine->getConfig($key);

        $this->assertEquals($value, $retrieved);
    }

    public function test_set_and_get_config_array(): void
    {
        $value = ['count' => 42, 'status' => 'ok'];

        $this->engine->setConfig('test_stats', $value);
        $retrieved = $this->engine->getConfig('test_stats');

        $this->assertIsArray($retrieved);
        $this->assertEquals(42, $retrieved['count']);
        $this->assertEquals('ok', $retrieved['status']);
    }

    public function test_config_is_persistent(): void
    {
        $key = 'test_persist';
        $this->engine->setConfig($key, 'hello');
        $this->assertEquals('hello', $this->engine->getConfig($key));
    }
}
