<?php

namespace Moaines\IllumiSearch\Console\Commands\Concerns;

use Illuminate\Support\Facades\Cache;

trait ChecksRebuildLock
{
    /**
     * Check if a rebuild is in progress.
     *
     * If called from a queue job, releases the job to retry later.
     * If called from a command, returns false so the caller can skip.
     *
     * @return bool true if no rebuild is in progress
     */
    protected function checkRebuildLock(): bool
    {
        $lock = Cache::lock('illumi-search:rebuild', 0);
        if ($lock->get()) {
            $lock->release();

            return true;
        }

        if (method_exists($this, 'release')) {
            $this->release(30);
        }

        return false;
    }
}
