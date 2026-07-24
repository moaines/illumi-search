<?php

namespace Moaines\IllumiSearch\Support;

/**
 * Shared static helpers for all engines.
 *
 * Provides utility methods that don't need dependency injection
 * and can be called from any context (engine, trait, command).
 */
class IllumiSearchHelper
{
    // HMAC constants shared by ChunkStorage, VocabService, TrigramIndex
    public const HMAC_ALGO = 'sha256';
    public const HMAC_KEY = 'illumi_chunk_v1';

    // Unicode script ranges for non-Latin scripts (CJK, Thai, Lao, Khmer, Myanmar, etc.)
    public const NON_LATIN = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}'
        . '\x{20000}-\x{2EBEF}\x{30000}-\x{323AF}'
        . '\x{3040}-\x{309F}\x{30A0}-\x{30FF}'
        . '\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}'
        . '\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}'
        . '\x{1780}-\x{17FF}\x{1000}-\x{109F}';

    /**
     * Check whether a string contains any CJK / Thai / Lao / Khmer / Myanmar characters.
     */
    public static function hasNonLatin(string $text): bool
    {
        return (bool) preg_match('/[' . self::NON_LATIN . ']/u', $text);
    }

    /**
     * Normalize a column name for storage: replace dots, arrows, and dashes with underscores.
     *
     * Used across all engines to handle dot-notation relations (e.g. "comments.body"
     * becomes "comments_body") and arrow/middleware syntax.
     */
    public static function normalizeColumnName(string $key): string
    {
        return str_replace(['.', '->', '-'], '_', $key);
    }

    /**
     * Build a model directory name from a fully-qualified class name.
     */
    public static function modelDirName(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);

        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $name));
    }
}
