<?php

namespace Moaines\IllumiSearch\Text;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Normalizer;

class UnicodeTextProcessor implements TextProcessor
{
    private const DIACRITICS_RULE = 'NFD; [:Nonspacing Mark:] Remove; NFC';

    private ?\Transliterator $diacriticsTransliterator = null;

    public function process(string $text, string $locale = 'en'): string
    {
        $text = $this->stripHtml($text);

        $text = $this->normalize($text);

        $text = $this->removeDiacritics($text);

        $text = $this->separateCjk($text);

        $text = $this->lowercase($text);

        $text = $this->cleanWhitespace($text);

        return trim($text);
    }

    public function normalize(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return $normalized !== false ? $normalized : $text;
    }

    public function removeDiacritics(string $text): string
    {
        if ($this->diacriticsTransliterator === null) {
            $this->diacriticsTransliterator = \Transliterator::create(self::DIACRITICS_RULE);
        }

        if ($this->diacriticsTransliterator === null) {
            return $text;
        }

        $result = $this->diacriticsTransliterator->transliterate($text);

        return $result !== false ? $result : $text;
    }

    public function lowercase(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Insert spaces between adjacent CJK characters so FTS5 tokenizes
     * each character individually instead of treating a whole sequence
     * as a single token.
     *
     * Covers: CJK Unified Ideographs, Extension A, Hiragana, Katakana, Hangul.
     */
    public function separateCjk(string $text): string
    {
        // Insert space after any CJK character that is followed by another CJK character.
        // Also insert space between CJK and non-CJK (to keep tokens clean).
        return preg_replace(
            '/[' . self::CJK_RANGE . '](?=[' . self::CJK_RANGE . '])/u',
            '$0 ',
            $text,
        ) ?? $text;
    }

    private const CJK_RANGE = '\x{4E00}-\x{9FFF}'       // CJK Unified Ideographs
        . '\x{3400}-\x{4DBF}'                            // Extension A
        . '\x{F900}-\x{FAFF}'                             // Compatibility Ideographs
        . '\x{3040}-\x{309F}'                             // Hiragana
        . '\x{30A0}-\x{30FF}'                             // Katakana
        . '\x{AC00}-\x{D7AF}';                            // Hangul Syllables

    public function cleanWhitespace(string $text): string
    {
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    public function stripHtml(string $text): string
    {
        return strip_tags($text);
    }
}
