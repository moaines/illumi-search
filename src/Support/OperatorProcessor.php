<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Contracts\Engine;

class OperatorProcessor
{
    private int $nearMaxDistance;

    public function __construct(?IllumiSearchConfig $config = null)
    {
        $cfg = $config ?? app(IllumiSearchConfig::class);
        $this->nearMaxDistance = max(1, $cfg->nearMaxDistance());
    }

    /**
     * Parse a query into structured operator groups.
     */
    public function parse(string $query): ParsedOperators
    {
        $terms = OperatorRegistry::tokenize($query);
        $ops = new ParsedOperators;
        $ops->nearMaxDistance = $this->nearMaxDistance;

        $pendingOp = '';
        $lastTerm = '';
        $inPhrase = false;

        foreach ($terms as $term) {
            if (empty($term)) {
                continue;
            }

            $upper = strtoupper($term);

            // Phrase
            if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
                $clean = trim($term, '"');
                if (! in_array($clean, $ops->phrases, true)) {
                    $ops->phrases[] = $clean;
                    $ops->required[] = $clean;
                }
                $inPhrase = true;
                $pendingOp = '';
                continue;
            }

            // Operator
            if (OperatorRegistry::isOperator($term)) {
                $pendingOp = $upper;
                continue;
            }

            // Clean the term
            $clean = preg_replace('/[^\p{L}\p{N}\*\-]/u', '', $term);
            if ($clean === '' || $clean === '-' || $clean === '*' || $clean === '--') {
                continue;
            }

            $clean = mb_strtolower($clean);

            match ($pendingOp) {
                'NEAR' => $this->addNearPair($ops, $lastTerm, $clean),
                'NOT' => $this->addToSet($ops->excluded, $clean),
                'OR' => $this->addToSet($ops->optional, $clean),
                default => $this->addToSet($ops->required, $clean),
            };

            $lastTerm = $clean;
            $pendingOp = '';
        }

        return $ops;
    }

    /**
     * Filter results by NEAR distance.
     *
     * @param  Result[]  $results
     * @return Result[]
     */
    public function filterNearResults(array $results, ParsedOperators $ops): array
    {
        if (empty($ops->nearPairs)) {
            return $results;
        }

        return collect($results)->filter(function ($result) use ($ops) {
            $raw = is_array($result) ? ($result['row'] ?? []) : ($result->raw ?? []);
            $text = mb_strtolower($raw['search_text'] ?? $result['title'] ?? $result->title ?? '');
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text) ?? [];

            foreach ($ops->nearPairs as [$a, $b]) {
                $posA = $this->findTokenPositions($tokens, $a);
                $posB = $this->findTokenPositions($tokens, $b);

                foreach ($posA as $pA) {
                    foreach ($posB as $pB) {
                        if (abs($pA - $pB) <= $ops->nearMaxDistance) {
                            return true;
                        }
                    }
                }
            }

            return false;
        })->values()->all();
    }

    /** @param list<string> $tokens */
    private function findTokenPositions(array $tokens, string $term): array
    {
        $positions = [];
        foreach ($tokens as $i => $token) {
            if (str_contains($token, $term)) {
                $positions[] = $i;
            }
        }

        return $positions;
    }

    private function addNearPair(ParsedOperators $ops, string $a, string $b): void
    {
        if ($a === '' || $b === '') {
            return;
        }
        $this->addToSet($ops->required, $a);
        $this->addToSet($ops->required, $b);
        $ops->nearPairs[] = [$a, $b];
    }

    private function addToSet(array &$set, string $term): void
    {
        if (! in_array($term, $set, true)) {
            $set[] = $term;
        }
    }
}
