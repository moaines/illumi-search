<?php

namespace Moaines\LaravelFts\Console\Commands;

use Illuminate\Console\Command;
use Moaines\LaravelFts\Console\Commands\Concerns\HasFtsFormatBytes;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Engines\SqliteFtsEngine;

class FtsDoctorCommand extends Command
{
    use HasFtsFormatBytes;
    protected $signature = 'fts:doctor';

    protected $description = 'Diagnose the FTS5 search environment';

    public function handle(FtsEngine $engine): int
    {
        $this->info('🔍 FTS Environment Diagnostics');
        $this->newLine();

        $allOk = true;

        // 1. PHP Extensions
        $this->line('1. PHP Extensions');
        $checks = [
            'sqlite3' => extension_loaded('sqlite3'),
            'intl'    => extension_loaded('intl'),
            'mbstring' => extension_loaded('mbstring'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        ];

        foreach ($checks as $ext => $loaded) {
            $status = $loaded ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("   {$status} ext-{$ext}");
            if (! $loaded) {
                $allOk = false;
            }
        }
        $this->newLine();

        // 2. FTS5 Support
        $this->line('2. SQLite FTS5 Support');
        try {
            $db = new \SQLite3(':memory:');
            $db->exec("CREATE VIRTUAL TABLE _fts_check USING fts5(content)");
            $version = \SQLite3::version();
            $this->line('   <fg=green>✓</> FTS5 is available (SQLite ' . $version['versionString'] . ')');
            $db->close();
        } catch (\Exception $e) {
            $this->line('   <fg=red>✗</> FTS5 is NOT available: ' . $e->getMessage());
            $allOk = false;
        }
        $this->newLine();

        // 3. Database
        $this->line('3. FTS Database');
        $dbPath = $engine->getDatabasePath();
        $dbDir = dirname($dbPath);

        if (file_exists($dbPath)) {
            $size = $engine->getDatabaseSize();
            $sizeHuman = $this->formatBytes($size);
            $isWritable = is_writable($dbPath);
            $isReadable = is_readable($dbPath);
            $isAbsolute = str_starts_with($dbPath, '/');
            $freeSpace = @disk_free_space($dbDir);
            $freeHuman = $freeSpace !== false ? $this->formatBytes((int) $freeSpace) : 'unknown';

            $this->line("   <fg=green>✓</> Path: {$dbPath}");
            $this->line("   <fg=green>✓</> Path type: " . ($isAbsolute ? 'absolute' : 'relative (via storage_path())'));
            $this->line("   <fg=green>✓</> Size: {$sizeHuman}");
            $this->line("   <fg=green>✓</> Free space on volume: {$freeHuman}");
            $this->line("   " . ($isReadable ? '<fg=green>✓</> Readable' : '<fg=red>✗</> Not readable'));
            $this->line("   " . ($isWritable ? '<fg=green>✓</> Writable' : '<fg=red>✗</> Not writable'));

            // Check indexes
            $stats = $engine->getIndexStats();
            if (! empty($stats)) {
                $this->newLine();
                $this->line('   Indexes:');
                foreach ($stats as $stat) {
                    $this->line("     - {$stat['model_class']}: {$stat['record_count']} records");
                }
            } else {
                $this->line('   <fg=yellow>⚠</> No indexes found. Run php artisan fts:rebuild');
            }
        } else {
            $this->line('   <fg=yellow>⚠</> Database does not exist yet');
            $this->line('   Path would be: ' . $dbPath);
            $this->line('   Run <comment>php artisan fts:rebuild</comment> to create it');
            $stats = [];
        }

        // 3b. Integrity check
        if (! empty($stats)) {
            $this->newLine();
            $this->line('   Integrity:');
            foreach ($stats as $stat) {
                $ok = $engine->integrityCheck($stat['model_class']);
                $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line("     {$icon} {$stat['model_class']}");
            }
        }
        $this->newLine();

        // 4. Config
        $this->line('4. Configuration');
        $configKeys = [
            'fts.indexing'              => config('fts.indexing'),
            'fts.mode'                  => config('fts.mode'),
            'fts.fts5.tokenizer'        => config('fts.fts5.tokenizer'),
            'fts.fts5.processor'        => config('fts.fts5.processor'),
            'fts.fts5.detail'           => config('fts.fts5.detail'),
            'fts.fts5.synchronous'      => config('fts.fts5.synchronous'),
            'fts.fts5.temp_store'       => config('fts.fts5.temp_store'),
            'fts.fts5.columnsize'       => config('fts.fts5.columnsize'),
            'fts.fts5.wal'              => config('fts.fts5.wal') ? 'true' : 'false',
            'fts.fts5.busy_timeout'     => config('fts.fts5.busy_timeout'),
            'fts.tenancy.enabled'       => config('fts.tenancy.enabled') ? 'true' : 'false',
            'fts.authorization.enabled' => config('fts.authorization.enabled') ? 'true' : 'false',
        ];

        foreach ($configKeys as $key => $value) {
            $this->line("   <info>{$key}</info> = {$value}");
        }
        $this->newLine();

        // 4b. Config validation
        $this->line('4b. Config Validation');
        $validations = [
            ['fts.mode', config('fts.mode'), ['basic', 'advanced']],
            ['fts.indexing', config('fts.indexing'), ['queue', 'sync', 'manual']],
            ['fts.fts5.processor', config('fts.fts5.processor'), ['unicode', 'stemming']],
            ['fts.fts5.detail', config('fts.fts5.detail'), ['full', 'column', 'none']],
            ['fts.fts5.synchronous', config('fts.fts5.synchronous'), ['NORMAL', 'FULL', 'OFF']],
            ['fts.fts5.temp_store', config('fts.fts5.temp_store'), ['MEMORY', 'FILE', 'DEFAULT']],
            ['fts.fts5.columnsize', config('fts.fts5.columnsize'), []],
            ['fts.fts5.wal', config('fts.fts5.wal'), []],
            ['fts.fts5.busy_timeout', config('fts.fts5.busy_timeout'), []],
        ];

        foreach ($validations as [$key, $value, $accepted]) {
            if (! empty($accepted)) {
                $isValid = in_array($value, $accepted, true);
            } elseif ($key === 'fts.fts5.columnsize') {
                $isValid = in_array((int) $value, [0, 1], true);
            } elseif ($key === 'fts.fts5.wal') {
                $isValid = in_array($value, [true, false, 'true', 'false'], true);
            } elseif ($key === 'fts.fts5.busy_timeout') {
                $isValid = is_numeric($value) && (int) $value >= 0;
            } else {
                $isValid = true;
            }

            $icon = $isValid ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("   {$icon} {$key} = " . json_encode($value));

            if (! $isValid) {
                $expected = $key === 'fts.fts5.busy_timeout'
                    ? 'must be a non-negative integer'
                    : 'accepted: ' . implode('|', $accepted);
                $this->line("     <fg=yellow>⚠ Expected {$expected}</>");
                $allOk = false;
            }
        }

        // 5. FTS5 Operators
        $this->line('5. FTS5 Operators');
        $rawOps = SqliteFtsEngine::getRawSupportedOperators();
        $allowedOps = SqliteFtsEngine::getSupportedOperators();

        foreach (['AND', 'OR', 'NOT', 'NEAR'] as $op) {
            $sqlite = in_array($op, $rawOps, true);
            $configOk = in_array($op, $allowedOps, true);
            $icon = $configOk ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $note = match (true) {
                $configOk => 'SQLite: ✓, Config: allowed',
                $sqlite && ! $configOk => 'SQLite: ✓, Config: restricted',
                default => 'SQLite: ✗, Config: —',
            };
            $this->line("   {$icon} {$op} ({$note})");
        }

        $this->newLine();

        // Summary
        if ($allOk) {
            $this->info('✅ All checks passed');
        } else {
            $this->error('❌ Some checks failed — review the output above');
        }

        return $allOk ? Command::SUCCESS : Command::FAILURE;
    }
}
