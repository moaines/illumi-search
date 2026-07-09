<?php

namespace Moaines\LaravelFts\Concerns;

trait HasQueryTerms
{
    protected function extractQueryTerms(string $query): array
    {
        $cleaned = preg_replace('/[":()^*\-]/', ' ', $query);
        $terms = array_filter(explode(' ', $cleaned ?? $query));

        return array_values(array_unique(array_map('trim', $terms)));
    }
}
