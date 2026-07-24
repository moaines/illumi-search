<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\ChunkStorage;
use Moaines\IllumiSearch\Tests\TestCase;

class VocabServiceTest extends TestCase
{
    private string $tempDir;
    private ChunkStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/test_vocab_' . uniqid();
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
    public function chunck_storage_decodes_hmac_format(): void
    {
        $path = $this->tempDir . '/test.php';
        $data = [['word' => 'laravel', 'ascii' => 'laravel', 'count' => 42]];

        $this->storage->atomicWrite($path, $data);
        $decoded = $this->storage->decodeFile($path);

        $this->assertEquals($data, $decoded);
    }

    /** @test */
    public function chunck_storage_rejects_tampered_hmac(): void
    {
        $path = $this->tempDir . '/test.php';
        $this->storage->atomicWrite($path, [['test' => 'data']]);

        $content = file_get_contents($path);
        $tampered = substr_replace($content, 'x', 50, 1);
        file_put_contents($path, $tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity check failed');
        $this->storage->decodeFile($path);
    }

    /** @test */
    public function chunck_storage_decodes_legacy_php_format(): void
    {
        $path = $this->tempDir . '/legacy.php';
        $payload = serialize(['word' => 'php', 'count' => 10]);

        file_put_contents($path, '<?php return unserialize(base64_decode("' . base64_encode($payload) . '"));');

        $decoded = $this->storage->decodeFile($path);
        $this->assertEquals(['word' => 'php', 'count' => 10], $decoded);
    }

    /** @test */
    public function chunck_storage_returns_null_for_empty_file(): void
    {
        $path = $this->tempDir . '/empty.php';
        file_put_contents($path, '');

        $this->assertNull($this->storage->decodeFile($path));
    }

    /** @test */
    public function chunck_storage_returns_null_for_corrupt_file(): void
    {
        $path = $this->tempDir . '/corrupt.php';
        file_put_contents($path, 'not valid serialized data');

        $this->assertNull($this->storage->decodeFile($path));
    }

    /** @test */
    public function chunck_storage_decodes_plain_serialize(): void
    {
        $path = $this->tempDir . '/plain.php';
        $data = ['key' => 'value'];
        file_put_contents($path, serialize($data));

        $decoded = $this->storage->decodeFile($path);
        $this->assertEquals($data, $decoded);
    }
}
