<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Str;

/**
 * Process items concurrently using pcntl_fork.
 *
 * Splits items into N groups, forks one child per group, and merges results.
 * Each child writes its partial results to a temp file, the parent collects.
 *
 * Requirements:
 *   - ext-pcntl (pcntl_fork + pcntl_waitpid)
 *   - CLI SAPI (disables forking in web context — built-in server deadlocks)
 *   - Serialize/unserialize for inter-process communication via temp files
 *
 * When requirements are not met, falls back to sequential processing.
 *
 * @internal
 */
class ConcurrentProcessor
{
    private int $workers;

    /**
     * @param  int  $workers  Number of parallel workers (1–8, clamped).
     *                        With fewer items than workers, runs sequentially.
     */
    public function __construct(int $workers = 4)
    {
        $this->workers = max(1, min($workers, 8));
    }

    /**
     * Process items — either sequentially or via forked workers.
     *
     * @param  array  $items  List of input values (one call to $processFn per item).
     * @param  callable  $processFn  Function(mixed $item): array  Must return an array (even if empty).
     * @return array Flat-merged results from all items.
     */
    public function run(array $items, callable $processFn): array
    {
        if (empty($items)) {
            return [];
        }

        if (count($items) < $this->workers || ! $this->canFork()) {
            return $this->processSequential($items, $processFn);
        }

        return $this->processConcurrent($items, $processFn);
    }

    /**
     * Whether pcntl_fork is available and safe to use.
     * Disabled in web SAPI (built-in server) and during unit tests.
     */
    private function canFork(): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && ! app()->runningUnitTests();
    }

    /**
     * Sequential fallback — processes all items in the current process.
     */
    private function processSequential(array $items, callable $processFn): array
    {
        $results = [];

        foreach ($items as $item) {
            $result = $processFn($item);

            if (is_array($result)) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Concurrent processing via pcntl_fork.
     *
     * Protocol:
     *   1. Split items into N groups (one per worker).
     *   2. Fork N children; each processes its group and writes serialized
     *      results to a unique temp file, then exits(0).
     *   3. Parent waits for all children via pcntl_waitpid.
     *   4. Parent reads and merges results from temp files, then cleans them up.
     *
     * If any fork fails, the remaining items fall back to sequential.
     */
    private function processConcurrent(array $items, callable $processFn): array
    {
        $groups = array_chunk($items, (int) ceil(count($items) / $this->workers));
        $tempFiles = [];
        $pids = [];

        // Register child signal handler to clean up temp files on abnormal exit
        $cleanup = function () use (&$tempFiles) {
            foreach ($tempFiles as $tf) {
                if (file_exists($tf)) {
                    @unlink($tf);
                }
            }
        };

        // Attempt to clean temp files on SIGTERM/SIGINT in the parent
        if (function_exists('pcntl_signal')) {
            $shutdown = function () use ($cleanup) {
                $cleanup();
                exit(1);
            };
            pcntl_signal(SIGTERM, $shutdown);
            pcntl_signal(SIGINT, $shutdown);
        }

        foreach ($groups as $i => $group) {
            $tempFile = sys_get_temp_dir() . '/illumi_fork_' . Str::random(16) . '_' . $i . '.tmp';
            $tempFiles[] = $tempFile;

            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed — clean up children already spawned, then fall back
                $cleanup();

                foreach ($pids as $spawnedPid) {
                    pcntl_waitpid($spawnedPid, $status);
                }

                return $this->processSequential($items, $processFn);
            }

            if ($pid === 0) {
                // ── Child process ──
                $childResults = $this->processSequential($group, $processFn);
                file_put_contents($tempFile, serialize($childResults));
                exit(0);
            }

            $pids[] = $pid;
        }

        // Parent: wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Restore default signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        // Collect and merge results
        $allResults = [];

        foreach ($tempFiles as $tf) {
            if (file_exists($tf)) {
                $data = unserialize(file_get_contents($tf));

                if (is_array($data)) {
                    $allResults = array_merge($allResults, $data);
                }

                @unlink($tf);
            }
        }

        return $allResults;
    }
}
