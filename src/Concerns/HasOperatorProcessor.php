<?php

namespace Moaines\IllumiSearch\Concerns;

use Moaines\IllumiSearch\Support\OperatorProcessor;

trait HasOperatorProcessor
{
    private OperatorProcessor $operatorProcessor;

    private function injectOperatorProcessor(?OperatorProcessor $processor): void
    {
        $this->operatorProcessor = $processor ?? app(OperatorProcessor::class);
    }

    private function nearFilterResults(array $results, string $safeQuery): array
    {
        // Skip parsing when no NEAR operator is present — saves tokenization + loop
        if (! \str_contains($safeQuery, 'NEAR')) {
            return $results;
        }

        $queryOps = $this->operatorProcessor->parse($safeQuery);

        return $this->operatorProcessor->filterNearResults($results, $queryOps);
    }
}
