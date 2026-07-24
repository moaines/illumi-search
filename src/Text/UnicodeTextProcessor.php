<?php

namespace Moaines\IllumiSearch\Text;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Normalizer;

class UnicodeTextProcessor implements TextProcessor
{
    use HasTextHelpers;

    public function process(string $text, string $locale = 'en'): string
    {
        $text = $this->stripHtml($text);
        $text = $this->normalize($text);
        $text = $this->removeDiacritics($text);
        $text = $this->separateCjk($text);
        $text = $this->lowercase($text);
        $text = $this->filterStopwords($text, $locale);
        $text = $this->truncateLongTokens($text);
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
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);

        if ($decomposed === false) {
            return $text;
        }

        $stripped = preg_replace('/\p{Mn}/u', '', $decomposed);
        $recomposed = Normalizer::normalize($stripped ?? $decomposed, Normalizer::FORM_C);

        return $recomposed !== false ? $recomposed : $text;
    }
}
