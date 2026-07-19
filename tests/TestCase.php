<?php

namespace Moaines\IllumiSearch\Tests;

use Moaines\IllumiSearch\IllumiSearchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            IllumiSearchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('fts.database_path', 'app/fts-test.sqlite');
        $app['config']->set('fts.indexing', 'sync');
        $app['config']->set('fts.fts5.prefix_lengths', [2, 3, 4]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $dbPath = storage_path('app/fts-test.sqlite');
        @unlink($dbPath);
    }

    protected function tearDown(): void
    {
        $dbPath = storage_path('app/fts-test.sqlite');
        @unlink($dbPath);

        parent::tearDown();
    }
}
