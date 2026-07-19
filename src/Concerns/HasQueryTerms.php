<?php

namespace Moaines\IllumiSearch\Concerns;

trait HasQueryTerms
{
    protected function extractQueryTerms(string $query): array
    {
        $cleaned = preg_replace('/[":()^*\-]/', ' ', $query);
        $terms = array_filter(explode(' ', $cleaned ?? $query));
        $terms = array_map('trim', $terms);

        // Filter out FTS5 operators — they are not search terms
        $operators = ['AND', 'OR', 'NOT', 'NEAR'];
        $terms = array_filter($terms, function ($t) use ($operators) {
            $base = preg_replace('/\/\d+$/', '', strtoupper($t));
            return ! in_array($base, $operators, true);
        });

        return array_values(array_unique($terms));
    }
}
