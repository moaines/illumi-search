<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines\Concerns;

trait ChecksMySql
{
    private static ?bool $_mysqlAvailable = null;

    protected function mysqlAvailable(): bool
    {
        if (self::$_mysqlAvailable !== null) {
            return self::$_mysqlAvailable;
        }

        try {
            new \PDO(
                'mysql:host=' . env('ILLUMI_SEARCH_MYSQL_HOST', '127.0.0.1') . ';port=' . env('ILLUMI_SEARCH_MYSQL_PORT', '3306'),
                env('ILLUMI_SEARCH_MYSQL_USERNAME', 'root'),
                env('ILLUMI_SEARCH_MYSQL_PASSWORD', ''),
                [\PDO::ATTR_TIMEOUT => 2]
            );
            self::$_mysqlAvailable = true;
        } catch (\Throwable) {
            self::$_mysqlAvailable = false;
        }

        return self::$_mysqlAvailable;
    }
}
