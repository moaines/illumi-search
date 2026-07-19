<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFormatBytes;
use Moaines\IllumiSearch\Contracts\Engine;

class StatusCommand extends Command
{
    use HasFormatBytes;
    protected $signature = 'illumi-search:status';

    protected $description = 'Show FTS5 index statistics';

    public function handle(Engine $engine): int
    {
        $path = $engine->getDatabasePath();

        if (! file_exists($path)) {
            $this->warn('FTS database does not exist yet. Run "php artisan illumi-search:rebuild" first.');

            return Command::SUCCESS;
        }

        $size = $engine->getDatabaseSize();
        $sizeHuman = $this->formatBytes($size);

        $this->info("FTS Database: {$path}");
        $this->line("Size: {$sizeHuman}");
        $this->newLine();

        $stats = $engine->getIndexStats();

        if (empty($stats)) {
            $this->warn('No models indexed.');
        } else {
            $totalRecords = collect($stats)->sum('record_count');
            $this->line("Total indexed records: {$totalRecords}");
            $this->line('Tables: '.count($stats));
            $this->newLine();

            $headers = ['Model', 'Records', 'Last Synced'];
            $rows = [];

            foreach ($stats as $stat) {
                $lastSynced = $stat['last_synced_at'] ?? 'Never';
                $rows[] = [
                    $stat['model_class'],
                    number_format($stat['record_count']),
                    $lastSynced,
                ];
            }

            $this->table($headers, $rows);
        }

        return Command::SUCCESS;
    }
}
