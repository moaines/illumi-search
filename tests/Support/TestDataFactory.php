<?php

namespace Moaines\IllumiSearch\Tests\Support;

use Moaines\IllumiSearch\Contracts\Engine;

/**
 * Reusable test data factory for engine integration tests.
 *
 * Provides helper methods to create documents, seed datasets,
 * and build common test scenarios across all engines.
 */
trait TestDataFactory
{
    /**
     * Create a single document array for upsert.
     *
     * @return array{model_id: int, document: array<string, string>}
     */
    protected function makeDoc(int $id, string $title, string $body): array
    {
        return [
            'model_id' => $id,
            'document' => compact('title', 'body'),
        ];
    }

    /**
     * Insert multiple documents into the engine.
     *
     * @param  array<int, array{model_id: int, document: array}>  $docs
     */
    protected function insertDocs(Engine $engine, string $modelClass, array $docs): void
    {
        foreach ($docs as $doc) {
            $engine->upsert($modelClass, $doc['model_id'], $doc['document']);
        }
    }

    /**
     * Insert a batch of documents using insertBatch.
     *
     * @param  array<int, array{model_id: int, document: array}>  $docs
     */
    protected function insertBatchDocs(Engine $engine, string $modelClass, array $docs): void
    {
        $engine->insertBatch($modelClass, $docs);
    }

    /**
     * Create a simple dataset with numbered titles and a shared body.
     *
     * @return array<int, array{model_id: int, document: array}>
     */
    protected function numberedDocs(int $count, string $body = 'content'): array
    {
        $docs = [];
        for ($i = 1; $i <= $count; $i++) {
            $docs[] = $this->makeDoc($i, "post $i", $body);
        }

        return $docs;
    }

    /**
     * Create a ranking test dataset with controlled weights.
     *
     * Returns [docs, expectedFirstId] where expectedFirstId is the
     * document that should rank highest for query "php" given that
     * title has weight 3 and body has weight 1.
     *
     * @return array{0: array, 1: int}
     */
    protected function rankingDataset(): array
    {
        $docs = [
            $this->makeDoc(1, 'php rare title', 'common filler text'),
            $this->makeDoc(2, 'other topic', 'rare word appears'),
            $this->makeDoc(3, 'second php title', 'php appears here too'),
            $this->makeDoc(4, 'unrelated', 'php somewhere'),
        ];

        return [$docs, 3];
    }

    /**
     * Create documents for boolean operator tests.
     *
     * @return array<int, array{model_id: int, document: array}>
     */
    protected function booleanTestDocs(): array
    {
        return [
            $this->makeDoc(1, 'php laravel guide', 'framework'),
            $this->makeDoc(2, 'php symfony guide', 'framework'),
            $this->makeDoc(3, 'python django guide', 'web'),
            $this->makeDoc(4, 'javascript react', 'frontend'),
        ];
    }
}
