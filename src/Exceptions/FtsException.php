<?php

namespace Moaines\IllumiSearch\Exceptions;

use Exception;

class FtsException extends Exception
{
    public static function extensionMissing(string $extension): self
    {
        return new self(sprintf(
            'The PHP extension "%s" is required but not loaded.',
            $extension
        ));
    }

    public static function fts5NotAvailable(): self
    {
        return new self(
            'SQLite FTS5 is not available. Recompile PHP/SQLite with SQLITE_ENABLE_FTS5=1.'
        );
    }

    public static function queryParseError(string $query, string $message): self
    {
        return new self(sprintf(
            'Failed to parse FTS5 query "%s": %s',
            $query,
            $message
        ));
    }

    public static function modelNotSearchable(string $modelClass): self
    {
        return new self(sprintf(
            'The model "%s" does not use the Searchable trait.',
            $modelClass
        ));
    }

    public static function indexCorrupted(string $path, string $message): self
    {
        return new self(sprintf(
            'FTS index at "%s" appears corrupted: %s. Run "php artisan fts:rebuild" to fix.',
            $path,
            $message
        ));
    }

    public static function databaseLocked(string $path): self
    {
        return new self(sprintf(
            'FTS database at "%s" is locked. Retry later.',
            $path
        ));
    }
}
