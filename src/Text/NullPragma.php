<?php

namespace Moaines\IllumiSearch\Text;

/**
 * Shared stub for engines that don't support PRAGMA queries.
 */
trait NullPragma
{
    public function getPragma(string $name): string|int|null
    {
        return null;
    }
}
