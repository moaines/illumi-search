<?php

namespace Moaines\IllumiSearch\Text;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Normalizer;

class UnicodeTextProcessor implements TextProcessor
{
    use HasTextHelpers;

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
}
