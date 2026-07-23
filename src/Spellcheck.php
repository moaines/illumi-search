<?php

namespace Moaines\IllumiSearch;

use Illuminate\Support\Collection;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Support\OperatorRegistry;

class Spellcheck
{
    private int $maxDistance = 2;

    private int $maxSuggestions = 5;

    public function __construct(
        private readonly Engine $engine,
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

    /**
     * @param string[] $modelClasses
     * @return Collection<int, string>
     */
    public function suggest(string $query, array $modelClasses = []): Collection
    {
        if (strlen(trim($query)) < 2) {
            return collect();
        }

        $suggestions = [];

        foreach ($this->extractTerms($query) as $term) {
            $suggestions = array_merge($suggestions, $this->engine->suggest(
                $term,
                $this->maxDistance,
                $this->maxSuggestions,
            ));
        }

        return collect($suggestions)
            ->unique()
            ->take($this->maxSuggestions)
            ->values();
    }

    /** @return string[] */
    protected function extractTerms(string $query): array
    {
        return OperatorRegistry::tokenize($query);
    }
}
