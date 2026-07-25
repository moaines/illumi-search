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
        $queryOps = $this->operatorProcessor->parse($safeQuery);

        return $this->operatorProcessor->filterNearResults($results, $queryOps);
    }
}
