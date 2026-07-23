<?php

namespace Moaines\IllumiSearch\Tests;

use Moaines\IllumiSearch\IllumiSearchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('illumi-search.processing.stopwords', []);

        $dbPath = storage_path('app/fts-test.sqlite');
        @unlink($dbPath);
    }

    protected function getPackageProviders($app): array
    {
        return [
            IllumiSearchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('illumi-search.engines.sqlite.database_path', 'app/fts-test.sqlite');
        $app['config']->set('illumi-search.indexing.mode', 'sync');
        $app['config']->set('illumi-search.engines.sqlite.fts5.prefix_lengths', [2, 3, 4]);
    }

    protected function tearDown(): void
    {
        $dbPath = storage_path('app/fts-test.sqlite');
        @unlink($dbPath);

        parent::tearDown();
    }
}
