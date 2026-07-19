<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFtsFormatBytes;
use Moaines\IllumiSearch\Contracts\FtsEngine;

class FtsOptimizeCommand extends Command
{
    use HasFtsFormatBytes;
    protected $signature = 'fts:optimize';

    protected $description = 'Optimize the FTS5 index (VACUUM + FTS5 merge)';

    public function handle(FtsEngine $engine): int
    {
        $path = $engine->getDatabasePath();

        if (! file_exists($path)) {
            $this->warn('FTS database does not exist. Nothing to optimize.');

            return Command::SUCCESS;
        }

        $beforeSize = $engine->getDatabaseSize();
        $this->info("Database: {$path}");
        $this->line("Size before: {$this->formatBytes($beforeSize)}");
        $this->newLine();

        $this->info('Running VACUUM...');
        $this->line('Running FTS5 merge optimization...');

        $results = $engine->optimize();

        $afterSize = $engine->getDatabaseSize();
        $saved = $beforeSize - $afterSize;

        $this->newLine();
        $this->info("Size after:  {$this->formatBytes($afterSize)}");

        if ($saved > 0) {
            $this->info("Space saved: {$this->formatBytes($saved)}");
        } else {
            $this->line('No space reclaimed (already optimized)');
        }

        $this->line("Tables optimized: {$results['tables_optimized']}");

        return Command::SUCCESS;
    }

}
