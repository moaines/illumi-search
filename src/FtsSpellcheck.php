<?php

namespace Moaines\LaravelFts;

use Illuminate\Support\Collection;
use Moaines\LaravelFts\Contracts\FtsEngine;

class FtsSpellcheck
{
    private int $maxDistance = 2;

    private int $maxSuggestions = 5;

    public function __construct(
        private readonly FtsEngine $engine,
    ) {}

    public function maxDistance(int $distance): static
    {
        $this->maxDistance = max(1, min($distance, 5));

        return $this;
    }

    public function maxSuggestions(int $count): static
    {
        $this->maxSuggestions = max(1, min($count, 20));

        return $this;
    }

    public function suggest(string $query, array $modelClasses = []): Collection
    {
        if (strlen(trim($query)) < 2) {
            return collect();
        }

        $terms = $this->extractTerms($query);
        $suggestions = collect();

        foreach ($terms as $term) {
            $candidates = $this->findSimilar($term, $modelClasses);
            $suggestions = $suggestions->merge($candidates);
        }

        return $suggestions
            ->unique()
            ->take($this->maxSuggestions)
            ->values();
    }

    /**
     * @return string[]
     */
    protected function extractTerms(string $query): array
    {
        $cleaned = preg_replace('/[":()^*\-]/', ' ', $query);
        $terms = array_filter(explode(' ', $cleaned ?? $query));

        return array_values(array_unique(array_map('trim', $terms)));
    }

    protected function findSimilar(string $term, array $modelClasses): array
    {
        $modelClasses = ! empty($modelClasses)
            ? $modelClasses
            : $this->engine->getIndexedModelClasses();

        $suggestions = [];

        foreach ($modelClasses as $modelClass) {
            $results = $this->engine->queryVocab($modelClass, $term, $this->maxDistance, $this->maxSuggestions);
            foreach ($results as $suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }
}
