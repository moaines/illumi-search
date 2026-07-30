<?php

namespace Moaines\IllumiSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Events\RebuildComplete;
use Moaines\IllumiSearch\IndexManager;
use Throwable;

class RebuildJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    /**
     * @param  string[]|null  $modelClasses
     */
    public function __construct(
        private readonly ?array $modelClasses = null,
        private readonly ?int $batchSize = null,
        private readonly bool $vacuum = false,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('illumi-search:rebuild'))
                ->dontRelease()
                ->expireAfter(600),
        ];
    }

    public function handle(Engine $engine): void
    {
        $lock = Cache::lock('illumi-search:rebuild', 600);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        $manager = app(IndexManager::class);
        $start = microtime(true);

        $results = $manager->rebuild(
            modelClasses: $this->modelClasses,
            batchSize: $this->batchSize,
            vacuum: $this->vacuum,
            progress: null,
        );

        $duration = round((microtime(true) - $start) * 1000);
        $totalRecords = collect($results)->sum('total');

        $engine->setConfig('rebuild_completed_at', now()->toIso8601String());
        $engine->setConfig('rebuild_duration_ms', (string) $duration);
        $engine->setConfig('rebuild_total_records', (string) $totalRecords);
        $engine->setConfig('rebuild_results', json_encode($results));

        RebuildComplete::dispatch($results);
    }

    public function failed(?Throwable $e): void
    {
        logger()->error('RebuildJob failed', [
            'message' => $e?->getMessage() ?? 'unknown error',
            'modelClasses' => $this->modelClasses,
            'exception' => $e,
        ]);

        Cache::lock('illumi-search:rebuild')->forceRelease();
    }
}
