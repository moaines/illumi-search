<?php

namespace Moaines\IllumiSearch\Text;

use Illuminate\Support\Str;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Stopwords\StopwordFilter;
use Moaines\IllumiSearch\Support\OperatorRegistry;

trait HasTextHelpers
{
    private const CJK_RANGE = '\x{4E00}-\x{9FFF}'
        . '\x{3400}-\x{4DBF}'
        . '\x{F900}-\x{FAFF}'
        . '\x{3040}-\x{309F}'
        . '\x{30A0}-\x{30FF}'
        . '\x{AC00}-\x{D7AF}';

    private static ?StopwordFilter $stopwordFilter = null;

    public function lowercase(string $text): string
    {
        return Str::lower($text);
    }

    public function separateCjk(string $text): string
    {
        return preg_replace(
            '/[' . self::CJK_RANGE . '](?=[' . self::CJK_RANGE . '])/u',
            '$0 ',
            $text,
        ) ?? $text;
    }

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

    public function removeDiacritics(string $s): string
    {
        $s = Str::lower($s);

        if (! extension_loaded('intl')) {
            return $s;
        }

        $normalized = normalizer_normalize($s, \Normalizer::FORM_KD);

        if ($normalized === false) {
            return $s;
        }

        return Str::of($normalized)->replaceMatches('/\p{Mn}/u', '')->toString();
    }

    public function contains(string $haystack, string $needle): bool
    {
        return Str::contains(
            $this->removeDiacritics($haystack),
            $this->removeDiacritics($needle),
        );
    }

    public function fuzzyContains(string $haystack, string $needle): bool
    {
        return $this->contains($haystack, $needle) || $this->similar($haystack, $needle);
    }

    public function similar(string $haystack, string $needle): bool
    {
        $haystack = $this->removeDiacritics($haystack);
        $needle = $this->removeDiacritics($needle);

        if (Str::contains($haystack, $needle)) {
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

    /**
     * Normalize a search query using the configured TextProcessor.
     */
    public function normalizeQuery(string $query): string
    {
        $processor = app(TextProcessor::class);

        return $processor->process($query);
    }

    /**
     * Split text into unique tokens, stripping surrounding punctuation.
     *
     * @return string[]
     */
    public function tokenizeText(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $words = preg_split('/\s+/', trim($text));
        $result = [];

        foreach ($words as $w) {
            $w = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $w);
            if ($w !== '') {
                $result[] = $w;
            }
        }

        return array_unique($result);
    }

    /**
     * Convert an ASCII word into trigrams (3-char sliding windows with # boundary).
     *
     * "laravel" → ["#la", "lar", "ara", "rav", "ave", "vel", "el#"]
     * "a" → ["#a#"]
     *
     * @return string[]
     */
    public function wordToTrigrams(string $word): array
    {
        $padded = '#' . $word . '#';
        $len = strlen($padded);
        $trigrams = [];

        for ($i = 0; $i < $len - 2; $i++) {
            $trigrams[] = substr($padded, $i, 3);
        }

        return array_values(array_unique($trigrams));
    }

    /**
     * @var array<string, string>|null
     */
    private static ?array $scriptPatterns = null;

    /**
     * Detect Unicode scripts present in a text string.
     *
     * @return string[]
     */
    public function scriptsOf(string $text): array
    {
        if (self::$scriptPatterns === null) {
            self::$scriptPatterns = [
                'Latin' => '\p{Latin}',
                'Cyrillic' => '\p{Cyrillic}',
                'Arabic' => '\p{Arabic}',
                'Han' => '\p{Han}',
                'Hiragana' => '\p{Hiragana}',
                'Katakana' => '\p{Katakana}',
                'Hangul' => '\p{Hangul}',
                'Greek' => '\p{Greek}',
                'Devanagari' => '\p{Devanagari}',
                'Hebrew' => '\p{Hebrew}',
                'Thai' => '\p{Thai}',
                'Tamil' => '\p{Tamil}',
                'Bengali' => '\p{Bengali}',
                'Gurmukhi' => '\p{Gurmukhi}',
                'Gujarati' => '\p{Gujarati}',
                'Oriya' => '\p{Oriya}',
                'Telugu' => '\p{Telugu}',
                'Kannada' => '\p{Kannada}',
                'Malayalam' => '\p{Malayalam}',
                'Sinhala' => '\p{Sinhala}',
                'Myanmar' => '\p{Myanmar}',
                'Khmer' => '\p{Khmer}',
                'Lao' => '\p{Lao}',
                'Tibetan' => '\p{Tibetan}',
                'Ethiopic' => '\p{Ethiopic}',
                'Georgian' => '\p{Georgian}',
                'Armenian' => '\p{Armenian}',
                'Cherokee' => '\p{Cherokee}',
                'Mongolian' => '\p{Mongolian}',
                'Canadian_Aboriginal' => '\p{Canadian_Aboriginal}',
            ];
        }

        $found = [];
        foreach (self::$scriptPatterns as $name => $pattern) {
            if (preg_match('/' . $pattern . '/u', $text) === 1) {
                $found[] = $name;
            }
        }

        return $found ?: ['Common'];
    }

    /** @return string[] */
    private function getStopwordLanguages(): array
    {
        $config = config('illumi-search.processing.stopwords', []);

        if (! is_array($config)) {
            return [];
        }

        return array_values(array_filter($config, fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * Normalize a column name for storage: replace dots, arrows, and dashes with underscores.
     */
    public function normalizeColumnName(string $key): string
    {
        return str_replace(['.', '->', '-'], '_', $key);
    }
}
