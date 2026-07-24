<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Tests\TestCase;

class IllumiSearchExceptionTest extends TestCase
{
    public static function factoryMethodProvider(): array
    {
        return [
            'extensionMissing' => ['extensionMissing', ['sqlite3'], 'sqlite3'],
            'fts5NotAvailable' => ['fts5NotAvailable', [], 'FTS5'],
            'queryParseError' => ['queryParseError', ['test query', 'syntax error'], 'test query'],
            'modelNotSearchable' => ['modelNotSearchable', ['App\Models\Foo'], 'App\Models\Foo'],
            'indexCorrupted' => ['indexCorrupted', ['/path/to/db', 'corrupt'], '/path/to/db'],
            'databaseLocked' => ['databaseLocked', ['/path/to/db'], 'locked'],
        ];
    }

    /** @dataProvider factoryMethodProvider */
    public function test_factory_methods(string $method, array $args, string $needle): void
    {
        $e = IllumiSearchException::$method(...$args);

        $this->assertInstanceOf(IllumiSearchException::class, $e);
        $this->assertStringContainsString($needle, $e->getMessage());
    }
}
