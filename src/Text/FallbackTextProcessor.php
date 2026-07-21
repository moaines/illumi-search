<?php

namespace Moaines\IllumiSearch\Text;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Symfony\Component\String\UnicodeString;

class FallbackTextProcessor implements TextProcessor
{
    use HasTextHelpers;

    public function process(string $text, string $locale = 'en'): string
    {
        $text = $this->stripHtml($text);
        $text = $this->removeDiacritics($text);
        $text = $this->separateCjk($text);
        $text = $this->lowercase($text);
        $text = $this->filterStopwords($text, $locale);
        $text = $this->truncateLongTokens($text);
        $text = $this->cleanWhitespace($text);

        return trim($text);
    }

    public function removeDiacritics(string $text): string
    {
        return (new UnicodeString($text))->ascii()->toString();
    }
}
