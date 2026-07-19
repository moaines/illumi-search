<?php

namespace Moaines\IllumiSearch\Contracts;

interface TextProcessor
{
    public function process(string $text, string $locale = 'en'): string;
}
