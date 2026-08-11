<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class DatabasePathTest extends TestCase
{
    public function test_default_relative_path_resolves_under_storage(): void
    {
        $path = config('illumi-search.engines.sqlite.database_path');
        $this->assertFalse(str_starts_with($path, '/'), 'default config should be relative');
        $this->assertStringStartsWith(storage_path(), storage_path($path));
    }

    public function test_engine_uses_configured_database_path(): void
    {
        config(['illumi-search.engines.sqlite.database_path' => 'app/fts-test.sqlite']);

        $engine = new SqliteEngine(databasePath: 'app/fts-test.sqlite');
        $this->assertSame('app/fts-test.sqlite', $engine->getDatabasePath());
    }

    public function test_absolute_database_path_is_used_as_is(): void
    {
        $target = '/tmp/illumi-abs-test.sqlite';
        @unlink($target);

        $engine = new SqliteEngine(databasePath: $target);
        $this->assertSame($target, $engine->getDatabasePath());

        @unlink($target);
    }
}
