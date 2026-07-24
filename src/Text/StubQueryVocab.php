<?php

namespace Moaines\IllumiSearch\Text;

/**
 * Shared stub for engines that don't support queryVocab (deprecated in favour of suggest).
 */
trait StubQueryVocab
{
    public function queryVocab(string $modelClass, string $term, int $maxDistance, int $limit): array
    {
        return [];
    }
}
