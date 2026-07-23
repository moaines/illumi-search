<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Support\ConfigQueue;
use Moaines\IllumiSearch\Tests\TestCase;

class ConfigQueueTest extends TestCase
{
    private ConfigQueue $queue;

    private string $key = 'test_queue_key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new ConfigQueue($this->app->make(Engine::class));
        $this->app->make(Engine::class)->setConfig($this->key, []);
    }

    public function test_push_adds_to_front(): void
    {
        $this->queue->push($this->key, 'b');
        $this->queue->push($this->key, 'a');

        $this->assertEquals(['a', 'b'], $this->app->make(Engine::class)->getConfig($this->key, []));
    }

    public function test_push_deduplicates(): void
    {
        $this->queue->push($this->key, 'a');
        $this->queue->push($this->key, 'a');

        $this->assertEquals(['a'], $this->app->make(Engine::class)->getConfig($this->key, []));
    }

    public function test_push_respects_max(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->queue->push($this->key, "item_{$i}", 5);
        }

        $data = $this->app->make(Engine::class)->getConfig($this->key, []);
        $this->assertCount(5, $data);
        $this->assertEquals('item_6', $data[0]);
        $this->assertEquals('item_2', $data[4]);
    }

    public function test_push_deduplicates_by_key(): void
    {
        $this->queue->push($this->key, ['id' => 1, 'name' => 'first'], 15, 'id');
        $this->queue->push($this->key, ['id' => 1, 'name' => 'second'], 15, 'id');

        $data = $this->app->make(Engine::class)->getConfig($this->key, []);
        $this->assertCount(1, $data);
        $this->assertEquals('second', $data[0]['name']);
    }

    public function test_remove(): void
    {
        $this->queue->push($this->key, 'a');
        $this->queue->push($this->key, 'b');

        $this->queue->remove($this->key, 'a');

        $this->assertEquals(['b'], $this->app->make(Engine::class)->getConfig($this->key, []));
    }

    public function test_remove_by_key(): void
    {
        $this->queue->push($this->key, ['id' => 1, 'name' => 'first'], 15, 'id');
        $this->queue->push($this->key, ['id' => 2, 'name' => 'second'], 15, 'id');

        $this->queue->remove($this->key, ['id' => 1], 'id');

        $data = $this->app->make(Engine::class)->getConfig($this->key, []);
        $this->assertCount(1, $data);
        $this->assertEquals('second', $data[0]['name']);
    }

    public function test_push_and_remove_with_sqlite_engine(): void
    {
        $path = storage_path('app/config-queue-test.sqlite');
        @unlink($path);

        $sqlite = new \Moaines\IllumiSearch\Engines\SqliteEngine($path);
        $queue = new \Moaines\IllumiSearch\Support\ConfigQueue($sqlite);

        $queue->push('test_recent', 'first', 10);
        $queue->push('test_recent', 'second', 10);
        $queue->push('test_recent', 'first', 10);

        $data = $sqlite->getConfig('test_recent', []);
        $this->assertCount(2, $data);
        $this->assertSame('first', $data[0]);

        $queue->remove('test_recent', 'first');
        $data = $sqlite->getConfig('test_recent', []);
        $this->assertCount(1, $data);

        @unlink($path);
    }
}
