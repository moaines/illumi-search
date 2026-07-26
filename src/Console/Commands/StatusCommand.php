<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFormatBytes;
use Moaines\IllumiSearch\Contracts\Engine;

class StatusCommand extends Command
{
    use HasFormatBytes;

    protected $signature = 'illumi-search:status';
    protected $description = 'Show index statistics';

    public function handle(Engine $engine): int
    {
        $driver = config('illumi-search.driver', 'sqlite');

        if ($driver === 'mysql') {
            $this->info('Engine: ' . $engine->getEngineVersion());
            $this->info('Connection: ' . $engine->getDatabasePath());
        } elseif ($driver === 'pgsql') {
            $this->info('Engine: ' . $engine->getEngineVersion());
            $this->info('Connection: ' . $engine->getDatabasePath());
        } else {
            $path = $engine->getDatabasePath();

            if (! file_exists($path)) {
                $this->warn('Database does not exist yet. Run "php artisan illumi-search:rebuild" first.');

                return Command::SUCCESS;
            }

            $size = $engine->getDatabaseSize();
            $sizeHuman = $this->formatBytes($size);

            $this->info("Database: {$path}");
            $this->line("Size: {$sizeHuman}");
        }

        $this->newLine();

        // Rebuild metadata (stored via setConfig after each rebuild)
        $completedAt = $engine->getConfig('rebuild_completed_at');
        if ($completedAt) {
            $ago = \Carbon\Carbon::parse($completedAt)->diffForHumans();
            $duration = $engine->getConfig('rebuild_duration_ms');
            $durationStr = $duration ? ' (' . round($duration / 1000, 1) . 's)' : '';
            $this->line("Last rebuild: <fg=yellow>{$ago}</>{$durationStr}");
        }

        $totalRecords = $engine->getConfig('rebuild_total_records');
        if ($totalRecords) {
            $this->line("Documents at rebuild: <fg=yellow>" . number_format((int) $totalRecords) . "</>");
        }

        $this->newLine();

        $stats = $engine->getIndexStats();

        if (empty($stats)) {
            $this->warn('No models indexed.');
        } else {
            $totalRecords = collect($stats)->sum('record_count');
            $this->line("Total indexed records: {$totalRecords}");
            $this->line('Tables: ' . count($stats));
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
