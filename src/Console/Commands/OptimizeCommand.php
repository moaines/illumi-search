<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFormatBytes;
use Moaines\IllumiSearch\Contracts\Engine;

class OptimizeCommand extends Command
{
    use HasFormatBytes;
    protected $signature = 'illumi-search:optimize';

    protected $description = 'Optimize the search index';

    public function handle(Engine $engine): int
    {
        $driver = config('illumi-search.driver', 'sqlite');
        $path = $engine->getDatabasePath();

        if ($driver !== 'mysql' && ! file_exists($path)) {
            $this->warn('Database does not exist. Nothing to optimize.');

            return Command::SUCCESS;
        }

        $beforeSize = $engine->getDatabaseSize();
        $this->info("Engine: {$path}");
        $this->line("Size before: {$this->formatBytes($beforeSize)}");
        $this->newLine();

        $this->info('Running optimization...');

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
