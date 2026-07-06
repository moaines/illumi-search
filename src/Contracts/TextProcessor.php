<?php

namespace Moaines\LaravelFts\Contracts;

interface TextProcessor
{
    public function process(string $text, string $locale = 'en'): string;
}
