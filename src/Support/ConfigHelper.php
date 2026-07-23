<?php

namespace Moaines\IllumiSearch\Support;

class ConfigHelper
{
    public static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $rawValue, mixed $default = null): mixed
    {
        $decoded = json_decode($rawValue, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $rawValue;
        }

        return $decoded;
    }
}
