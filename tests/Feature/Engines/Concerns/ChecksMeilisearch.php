<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines\Concerns;

trait ChecksMeilisearch
{
    private static ?bool $_meilisearchAvailable = null;

    protected function meilisearchAvailable(): bool
    {
        if (self::$_meilisearchAvailable !== null) {
            return self::$_meilisearchAvailable;
        }

        try {
            $host = config('illumi-search.engines.meilisearch.host', 'http://localhost:7700');
            $ch = curl_init($host . '/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            self::$_meilisearchAvailable = $httpCode === 200;
        } catch (\Throwable) {
            self::$_meilisearchAvailable = false;
        }

        return self::$_meilisearchAvailable;
    }
}
