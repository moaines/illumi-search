<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Contracts\Engine;

class OperatorRegistry
{
    public const OPERATORS = Engine::OPERATORS;

    public const PHRASE_PATTERN = '/"[^"]+"|[^\s]+/u';

    /**
     * Tokenize a search query into terms and phrases.
     *
     * @return list<string>
     */
    public static function tokenize(string $query): array
    {
        if (preg_match_all(self::PHRASE_PATTERN, $query, $matches)) {
            return $matches[0];
        }

        return [];
    }

    public static function isOperator(string $term): bool
    {
        return in_array(strtoupper($term), self::OPERATORS, true);
    }

    /**
     * Mask operators in text so they survive stopword filtering.
     * Returns [masked_text, replacements] where replacements maps
     * placeholders back to original operators.
     */
    public static function maskOperators(string $text): array
    {
        $replacements = [];

        $result = preg_replace_callback(
            '/\b(' . implode('|', self::OPERATORS) . ')\b/ui',
            function ($m) use (&$replacements) {
                $key = '__OP' . count($replacements) . '__';
                $replacements[$key] = $m[1];

                return $key;
            },
            $text,
        );

        return [$result ?? $text, $replacements];
    }

    /**
     * Restore operators that were masked by maskOperators.
     */
    public static function unmaskOperators(string $text, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
