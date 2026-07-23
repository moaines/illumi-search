<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Contracts\TextProcessor;

class IdentityProcessor implements TextProcessor
{
    public function process(string $text, string $locale = 'en'): string
    {
        return $text;
    }
}
