<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\PgsqlEngine;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\QualityTestSuite;
use Moaines\IllumiSearch\Tests\TestCase;

class PgsqlQualityTest extends TestCase
{
    use QualityTestSuite;

    protected function createEngine(): Engine
    {
        try {
            new \PDO(
                'pgsql:host=' . env('ILLUMI_SEARCH_PGSQL_HOST', '127.0.0.1') . ';port=' . env('ILLUMI_SEARCH_PGSQL_PORT', '5432') . ';dbname=' . env('ILLUMI_SEARCH_PGSQL_DATABASE', 'test-illumi-search'),
                env('ILLUMI_SEARCH_PGSQL_USERNAME', 'postgres'),
                env('ILLUMI_SEARCH_PGSQL_PASSWORD', 'password'),
                [\PDO::ATTR_TIMEOUT => 2]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL connection not available: ' . $e->getMessage());
        }

        config(['illumi-search.engines.pgsql.connection.database' => env('ILLUMI_SEARCH_PGSQL_DATABASE', 'test-illumi-search')]);

        $engine = new PgsqlEngine;
        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);

        return $engine;
    }
}
