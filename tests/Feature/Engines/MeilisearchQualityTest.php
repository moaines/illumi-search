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

    /**
     * Parentheses grouping not supported — see README limitations.
     * SQLite/PgSQL handle it natively; Meilisearch does not.
     */
    #[Test]
    public function combined_and_or_not(): void
    {
        $this->markTestSkipped('() grouping not supported by Meilisearch');
    }

    /**
     * SQL injection test is not applicable to Meilisearch (HTTP API, no SQL).
     */
    #[Test]
    public function injection_attempt_returns_safe_results(): void
    {
        $this->markTestSkipped('SQL injection test not applicable to Meilisearch');
    }
}
