<?php

namespace Moaines\LaravelFts\Tests\TestSupport\Processors;

use Moaines\LaravelFts\Contracts\TextProcessor;

class PorterStemmerProcessor implements TextProcessor
{
    public function process(string $text, string $locale = 'en'): string
    {
        $text = mb_strtolower($text);
        $words = preg_split('/\s+/', $text);
        $stemmed = array_map(function (string $word): string {
            $word = preg_replace('/ing$/', '', $word);
            $word = preg_replace('/ed$/', '', $word);
            $word = preg_replace('/s$/', '', $word);

            return $word;
        }, $words);

        return implode(' ', $stemmed);
    }
}
