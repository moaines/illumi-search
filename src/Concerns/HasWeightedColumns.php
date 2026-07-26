<?php

namespace Moaines\IllumiSearch\Concerns;

trait HasWeightedColumns
{
    protected function weightColumnNames(?int $maxWeight = null): string
    {
        $maxWeight = $maxWeight ?? $this->maxWeight;
        return implode(', ', array_map(fn ($w) => "text_w{$w}", range(1, $maxWeight)));
    }

    protected function modelTypePlaceholders(array $modelClasses): array
    {
        $placeholders = implode(', ', array_fill(0, count($modelClasses), '?'));

        return [$placeholders, $modelClasses];
    }
}
