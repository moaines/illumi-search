<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\QualityTestSuite;
use Moaines\IllumiSearch\Tests\TestCase;

class SqliteQualityTest extends TestCase
{
    use QualityTestSuite;

    protected function createEngine(): Engine
    {
        $path = sys_get_temp_dir() . '/illumi_quality_sqlite_' . uniqid() . '.sqlite';
        $engine = new SqliteEngine($path);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        return $engine;
    }
}
