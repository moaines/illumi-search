<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\QualityTestSuite;
use Moaines\IllumiSearch\Tests\TestCase;

class FileQualityTest extends TestCase
{
    use QualityTestSuite;

    protected function createEngine(): Engine
    {
        $path = sys_get_temp_dir() . '/illumi_quality_file_' . uniqid();
        $engine = new FileEngine($path);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        return $engine;
    }
}
