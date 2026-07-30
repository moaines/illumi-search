<?php

namespace Moaines\IllumiSearch\Tests\Feature\Jobs;

use Illuminate\Support\Facades\Cache;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\IndexManager;
use Moaines\IllumiSearch\Jobs\RebuildJob;
use Moaines\IllumiSearch\Tests\TestCase;

class RebuildJobTest extends TestCase
{
    public function test_handle_calls_index_manager_rebuild(): void
    {
        $results = [
            ['model' => 'App\Models\Post', 'status' => 'indexed', 'total' => 10],
            ['model' => 'App\Models\Book', 'status' => 'indexed', 'total' => 20],
        ];

        $manager = $this->createMock(IndexManager::class);
        $manager->expects($this->once())
            ->method('rebuild')
            ->with(null, null, false, null)
            ->willReturn($results);

        app()->instance(IndexManager::class, $manager);

        $expectedKeys = ['rebuild_completed_at', 'rebuild_duration_ms', 'rebuild_total_records', 'rebuild_results'];
        $actualKeys = [];

        $engine = $this->createMock(Engine::class);
        $engine->method('setConfig')
            ->willReturnCallback(function (string $key, mixed $value) use (&$actualKeys): void {
                $actualKeys[] = $key;
            });

        $job = new RebuildJob;
        $job->handle($engine);

        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_handle_respects_rebuild_lock(): void
    {
        $lock = Cache::lock('illumi-search:rebuild', 10);
        $lock->get();

        $manager = $this->createMock(IndexManager::class);
        $manager->expects($this->never())->method('rebuild');

        app()->instance(IndexManager::class, $manager);

        $engine = $this->createMock(Engine::class);
        $engine->expects($this->never())->method('setConfig');

        $job = new RebuildJob;
        $job->handle($engine);

        $lock->release();
    }

    public function test_stores_rebuild_metadata(): void
    {
        $results = [
            ['model' => 'App\Models\Test', 'status' => 'indexed', 'total' => 42],
        ];

        $manager = $this->createMock(IndexManager::class);
        $manager->method('rebuild')->willReturn($results);

        app()->instance(IndexManager::class, $manager);

        $engine = $this->createMock(Engine::class);

        $stored = [];
        $engine->method('setConfig')
            ->willReturnCallback(function (string $key, mixed $value) use (&$stored): void {
                $stored[$key] = $value;
            });

        $job = new RebuildJob;
        $job->handle($engine);

        $this->assertArrayHasKey('rebuild_completed_at', $stored);
        $this->assertArrayHasKey('rebuild_duration_ms', $stored);
        $this->assertArrayHasKey('rebuild_total_records', $stored);
        $this->assertSame('42', $stored['rebuild_total_records']);
    }

    public function test_fails_gracefully(): void
    {
        $lock = Cache::lock('illumi-search:rebuild', 10);
        $lock->get();

        $job = new RebuildJob;
        $job->failed(new \RuntimeException('test error'));

        $this->assertTrue(Cache::lock('illumi-search:rebuild', 10)->get());
        $lock->release();
    }
}
