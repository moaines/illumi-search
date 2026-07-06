<?php

namespace Moaines\LaravelFts\Exceptions;

class FtsExtensionMissingException extends FtsException
{
    public function __construct(string $extension)
    {
        parent::__construct(
            "Missing PHP extension: {$extension}. Install it and ensure it is enabled in php.ini."
        );
    }
}
