<?php

namespace Moaines\IllumiSearch\Text;

trait HasTextHelpers
{
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
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    public function stripHtml(string $text): string
    {
        return strip_tags($text);
    }
}
