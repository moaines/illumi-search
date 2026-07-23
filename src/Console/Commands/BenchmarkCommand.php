<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Support\Benchmark\BenchmarkRunner;
use Moaines\IllumiSearch\Support\Benchmark\ReportRenderer;

class BenchmarkCommand extends Command
{
    private const ENGINE_FACTORIES = [
        'SQLite' => 'createSqliteEngine',
        'MySQL' => 'createMySqlEngine',
    ];

    protected $signature = 'illumi-search:benchmark
        {--docs=1000 : Number of documents to index}
        {--all-engines : Benchmark both SQLite and MySQL engines}
        {--format=table : Output format (table|json)}
        {--memory=512M : Memory limit for the benchmark process}
        {--timeout=300 : Max execution time in seconds}
        {--mode=processed : Indexing mode: processed (normalized), raw (no normalization), both}';

    protected $description = 'Benchmark search engine performance and quality';

    public function handle(Engine $engine): int
    {
        $memory = $this->option('memory');
        $timeout = (int) $this->option('timeout');

        if ($memory !== '-1') {
            ini_set('memory_limit', $memory);
        } else {
            ini_set('memory_limit', '-1');
        }
        set_time_limit($timeout);

        $totalDocs = (int) $this->option('docs');
        $format = $this->option('format');
        $verbose = $this->option('verbose') ?? false;
        $allEngines = $this->option('all-engines');
        $mode = $this->option('mode');
        $seedPath = base_path('database/seed.json');

        if (! file_exists($seedPath)) {
            $seedPath = null;
        }

        $renderer = new ReportRenderer;

        $currentName = $engine->getEngineStatus()['driver'] ?? (new \ReflectionClass($engine))->getShortName();

        $enginesToRun = [];

        if ($allEngines) {
            foreach (self::ENGINE_FACTORIES as $name => $factoryMethod) {
                if ($name === $currentName) {
                    $enginesToRun[] = [$engine, $name];
                } else {
                    $eng = $this->{$factoryMethod}();
                    if ($eng !== null) {
                        $enginesToRun[] = [$eng, $name];
                    }
                }
            }
        } else {
            $enginesToRun[] = [$engine, $currentName];
        }

        $modes = $mode === 'both' ? ['processed', 'raw'] : [$mode];

        foreach ($modes as $currentMode) {
            if (count($modes) > 1) {
                $this->info("\n<options=bold>=== Mode: {$currentMode} ===</>");
            }

            foreach ($enginesToRun as [$eng, $engName]) {
                $this->runSingle($eng, $engName, $totalDocs, $seedPath, $verbose, $renderer, $currentMode);
                try {
                    $eng->dropTable('App\Models\BenchmarkPost');
                } catch (\Exception) {
                }
            }
        }

        $renderer->render($this->output, $format);

        return Command::SUCCESS;
    }

    private function runSingle(Engine $engine, string $name, int $totalDocs, ?string $seedPath, bool $verbose, ReportRenderer $renderer, string $mode = 'processed'): void
    {
        $this->info("Benchmarking {$name} ({$mode})...");

        $runner = new BenchmarkRunner($engine, $seedPath);
        $results = $runner->run($totalDocs, $verbose, $mode);

        $renderer->addEngineResults($name, $results);

        try {
            $engine->dropTable('App\Models\BenchmarkPost');
        } catch (\Exception) {
        }
    }

    private function createSqliteEngine(): ?Engine
    {
        try {
            $path = storage_path('app/benchmark.sqlite');
            if (file_exists($path)) {
                @unlink($path);
            }

            $engine = new SqliteEngine($path);

            return $engine;
        } catch (\Exception) {
            $this->warn('Could not create SQLite engine for comparison.');

            return null;
        }
    }

    private function createMySqlEngine(): ?Engine
    {
        try {
            $engine = new MySqlEngine;
            $engine->createTable('App\Models\BenchmarkPost', ['title', 'body']);

            return $engine;
        } catch (\Exception $e) {
            $this->warn('Could not connect to MySQL: ' . $e->getMessage());

            return null;
        }
    }
}
