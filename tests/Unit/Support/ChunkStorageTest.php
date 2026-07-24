<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\ChunkStorage;
use Moaines\IllumiSearch\Tests\TestCase;

class ChunkStorageTest extends TestCase
{
    private string $tempDir;
    private ChunkStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/test_chunks_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->storage = new ChunkStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /** @test */
    public function atomic_write_then_decode_returns_same_data(): void
    {
        $path = $this->tempDir . '/test.php';
        $data = [['id' => 1, 'title' => 'test']];

        $this->storage->atomicWrite($path, $data);
        $decoded = $this->storage->decodeFile($path);

        $this->assertEquals($data, $decoded);
    }

    /** @test */
    public function decode_rejects_tampered_hmac(): void
    {
        $path = $this->tempDir . '/test.php';
        $this->storage->atomicWrite($path, [['test' => 'data']]);

        // Tamper with file content (modify a byte)
        $content = file_get_contents($path);
        $tampered = substr_replace($content, 'x', 50, 1);
        file_put_contents($path, $tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity check failed');
        $this->storage->decodeFile($path);
    }

    /** @test */
    public function decode_handles_hmac_only_not_throw_for_valid(): void
    {
        $path = $this->tempDir . '/test.php';
        $data = [['key' => 'value']];

        $this->storage->atomicWrite($path, $data);
        $content = file_get_contents($path);

        // Verify it starts with hmac:
        $this->assertStringStartsWith('hmac:', $content);

        // Decoding should work
        $decoded = $this->storage->decodeFile($path);
        $this->assertEquals($data, $decoded);
    }

    /** @test */
    public function list_chunks_returns_php_files(): void
    {
        file_put_contents($this->tempDir . '/0000.php', 'data');
        file_put_contents($this->tempDir . '/0001.php', 'data');
        file_put_contents($this->tempDir . '/notes.txt', 'skip me');

        $chunks = $this->storage->listChunks($this->tempDir);

        $this->assertCount(2, $chunks);
        foreach ($chunks as $c) {
            $this->assertStringEndsWith('.php', $c);
        }
    }

    /** @test */
    public function ensure_dir_creates_directory(): void
    {
        $dir = $this->tempDir . '/nested/sub/dir';
        $this->storage->ensureDir($dir);

        $this->assertDirectoryExists($dir);
    }
}
