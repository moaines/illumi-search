<?php

namespace Moaines\IllumiSearch\Text;

use Illuminate\Support\Str;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Stopwords\StopwordFilter;
use Moaines\IllumiSearch\Support\OperatorRegistry;

trait HasTextHelpers
{
    private static ?StopwordFilter $stopwordFilter = null;

    public function lowercase(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    public function separateCjk(string $text): string
    {
        return preg_replace(
            '/[' . self::CJK_RANGE . '](?=[' . self::CJK_RANGE . '])/u',
            '$0 ',
            $text,
        ) ?? $text;
    }

    private const CJK_RANGE = '\x{4E00}-\x{9FFF}'
        . '\x{3400}-\x{4DBF}'
        . '\x{F900}-\x{FAFF}'
        . '\x{3040}-\x{309F}'
        . '\x{30A0}-\x{30FF}'
        . '\x{AC00}-\x{D7AF}';

    public function cleanWhitespace(string $text): string
    {
        return Str::squish($text);
    }

    public function stripHtml(string $text): string
    {
        return strip_tags($text);
    }

    public function filterStopwords(string $text, string $locale = 'en'): string
    {
        try {
            $languages = $this->getStopwordLanguages();

            if (empty($languages)) {
                return $text;
            }

            // Mask operators AND/OR/NOT/NEAR so they survive stopword filtering
            // (e.g. "not" is in the English stopword list)
            [$text, $replacements] = OperatorRegistry::maskOperators($text);

            if (self::$stopwordFilter === null) {
                self::$stopwordFilter = new StopwordFilter;
            }

            foreach ($languages as $lang) {
                $text = self::$stopwordFilter->filter($text, $lang);
            }

            $text = OperatorRegistry::unmaskOperators($text, $replacements);
        } catch (\Throwable) {
            return $text;
        }

        return $text;
    }

    public function truncateLongTokens(string $text, int $maxLength = 32): string
    {
        return preg_replace_callback(
            '/\S{' . $maxLength . ',}/u',
            fn ($m) => mb_strcut($m[0], 0, $maxLength),
            $text,
        ) ?? $text;
    }

    public function normalize(string $s): string
    {
        $s = mb_strtolower($s);

        $normalized = normalizer_normalize($s, \Normalizer::FORM_KD);

        if ($normalized === false) {
            return $s;
        }

        return preg_replace('/\p{Mn}/u', '', $normalized);
    }

    public function contains(string $haystack, string $needle): bool
    {
        return mb_strpos(self::normalize($haystack), self::normalize($needle)) !== false;
    }

    public function fuzzyContains(string $haystack, string $needle): bool
    {
        return $this->contains($haystack, $needle) || $this->similar($haystack, $needle);
    }

    public function similar(string $haystack, string $needle): bool
    {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);

        if (str_contains($haystack, $needle)) {
            return true;
        }

        $distance = self::levenshteinDistance($haystack, $needle);
        $max = $needle === '' ? 0 : max(1, intdiv(mb_strlen($needle), 3));

        return $distance !== -1 && $distance <= $max;
    }

    public static function levenshteinDistance(string $a, string $b): int
    {
        $a = normalizer_normalize($a, \Normalizer::FORM_KD) ?: $a;
        $b = normalizer_normalize($b, \Normalizer::FORM_KD) ?: $b;

        if (function_exists('grapheme_levenshtein')) {
            return grapheme_levenshtein($a, $b);
        }

        return levenshtein($a, $b);
    }

    /** @return string[] */
    private function getStopwordLanguages(): array
    {
        $config = config('illumi-search.stopwords', []);

        if (! is_array($config)) {
            return [];
        }

        return array_values(array_filter($config, fn ($v) => is_string($v) && $v !== ''));
    }
}
