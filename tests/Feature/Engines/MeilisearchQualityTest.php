<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use PHPUnit\Framework\Attributes\Test;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MeilisearchEngine;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\ChecksMeilisearch;
use Moaines\IllumiSearch\Tests\Feature\Engines\Concerns\QualityTestSuite;
use Moaines\IllumiSearch\Tests\TestCase;

class MeilisearchQualityTest extends TestCase
{
    use ChecksMeilisearch;
    use QualityTestSuite;

    protected function createEngine(): Engine
    {
        if (! $this->meilisearchAvailable()) {
            $this->markTestSkipped('Meilisearch is not available');
        }

        $engine = new MeilisearchEngine(
            host: config('illumi-search.engines.meilisearch.host', 'http://localhost:7700'),
            apiKey: config('illumi-search.engines.meilisearch.api_key', 'masterKey'),
        );

        try {
            $engine->dropTable(self::QT_MODEL);
        } catch (\Exception) {
        }

        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);

        return $engine;
    }
}
