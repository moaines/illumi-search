<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Tests\TestCase;

class DatabasePathTest extends TestCase
{
    public function test_relative_path_resolves_via_storage_path(): void
    {
        $path = config('fts.database_path');
        $fullPath = str_starts_with($path, '/') ? $path : storage_path($path);

        $this->assertStringStartsWith(storage_path(), $fullPath);
        $this->assertStringContainsString('fts', $fullPath);
    }

    public function test_absolute_path_is_used_as_is(): void
    {
        $path = '/data/fts/fts-index.sqlite';
        $fullPath = str_starts_with($path, '/') ? $path : storage_path($path);

        $this->assertSame($path, $fullPath);
    }

    public function test_custom_relative_path_resolves_correctly(): void
    {
        $path = 'custom/path/index.sqlite';
        $fullPath = str_starts_with($path, '/') ? $path : storage_path($path);

        $this->assertSame(storage_path('custom/path/index.sqlite'), $fullPath);
    }

    public function test_absolute_path_without_storage_prefix(): void
    {
        $path = '/mnt/persistent/fts/fts-index.sqlite';
        $fullPath = str_starts_with($path, '/') ? $path : storage_path($path);

        $this->assertStringStartsWith('/mnt/persistent/', $fullPath);
        $this->assertStringNotContainsString('storage', $fullPath);
    }
}
