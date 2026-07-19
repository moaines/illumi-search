<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFtsFormatBytes;
use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Engines\SqliteFtsEngine;

class FtsDoctorCommand extends Command
{
    use HasFtsFormatBytes;
    protected $signature = 'fts:doctor';

    protected $description = 'Diagnose the FTS5 search environment';

    private bool $allOk = true;

    public function handle(FtsEngine $engine): int
    {
        $this->info('🔍 FTS Environment Diagnostics');
        $this->newLine();

        $this->checkPhpExtensions();
        $this->checkFts5Support();
        $stats = $this->checkDatabase($engine);
        $this->checkIntegrity($engine, $stats);
        $this->showConfig();
        $this->validateConfig();
        $this->checkOperators();

        $this->newLine();
        if ($this->allOk) {
            $this->info('✅ All checks passed');
        } else {
            $this->error('❌ Some checks failed — review the output above');
        }

        return $this->allOk ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkPhpExtensions(): void
    {
        $this->line('1. PHP Extensions');
        foreach (['sqlite3', 'intl', 'mbstring', 'pdo_sqlite'] as $ext) {
            $loaded = extension_loaded($ext);
            $this->line('   ' . ($loaded ? '<fg=green>✓</>' : '<fg=red>✗</>') . " ext-{$ext}");
            if (! $loaded) $this->allOk = false;
        }
        $this->newLine();
    }

    private function checkFts5Support(): void
    {
        $this->line('2. SQLite FTS5 Support');
        try {
            $db = new \SQLite3(':memory:');
            $db->exec("CREATE VIRTUAL TABLE _fts_check USING fts5(content)");
            $version = \SQLite3::version();
            $this->line('   <fg=green>✓</> FTS5 is available (SQLite ' . $version['versionString'] . ')');
            $db->close();
        } catch (\Exception $e) {
            $this->line('   <fg=red>✗</> FTS5 is NOT available: ' . $e->getMessage());
            $this->allOk = false;
        }
        $this->newLine();
    }

    private function checkDatabase(FtsEngine $engine): array
    {
        $this->line('3. FTS Database');
        $dbPath = $engine->getDatabasePath();

        if (! file_exists($dbPath)) {
            $this->line('   <fg=yellow>⚠</> Database does not exist yet');
            $this->line('   Path would be: ' . $dbPath);
            $this->line('   Run <comment>php artisan fts:rebuild</comment> to create it');
            $this->newLine();
            return [];
        }

        $sizeHuman = $this->formatBytes($engine->getDatabaseSize());
        $freeSpace = @disk_free_space(dirname($dbPath));
        $freeHuman = $freeSpace !== false ? $this->formatBytes((int) $freeSpace) : 'unknown';
        $isAbsolute = str_starts_with($dbPath, '/');

        $this->line("   <fg=green>✓</> Path: {$dbPath}");
        $this->line("   <fg=green>✓</> Path type: " . ($isAbsolute ? 'absolute' : 'relative (via storage_path())'));
        $this->line("   <fg=green>✓</> Size: {$sizeHuman}");
        $this->line("   <fg=green>✓</> Free space on volume: {$freeHuman}");
        $this->line('   ' . (is_readable($dbPath) ? '<fg=green>✓</> Readable' : '<fg=red>✗</> Not readable'));
        $this->line('   ' . (is_writable($dbPath) ? '<fg=green>✓</> Writable' : '<fg=red>✗</> Not writable'));

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
        $this->newLine();

        return $stats;
    }

    private function checkIntegrity(FtsEngine $engine, array $stats): void
    {
        if (empty($stats)) return;

        $this->line('3b. Integrity');
        foreach ($stats as $stat) {
            $ok = $engine->integrityCheck($stat['model_class']);
            $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("     {$icon} {$stat['model_class']}");
        }
        $this->newLine();
    }

    private function showConfig(): void
    {
        $this->line('4. Configuration');
        $keys = [
            'fts.indexing', 'fts.mode', 'fts.fts5.tokenizer', 'fts.fts5.processor',
            'fts.fts5.detail', 'fts.fts5.synchronous', 'fts.fts5.temp_store',
            'fts.fts5.columnsize', 'fts.fts5.wal', 'fts.fts5.busy_timeout',
            'fts.tenancy.enabled', 'fts.authorization.enabled',
        ];
        foreach ($keys as $key) {
            $value = config($key);
            $display = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            $this->line("   <info>{$key}</info> = {$display}");
        }
        $this->newLine();
    }

    private function validateConfig(): void
    {
        $this->line('4b. Config Validation');
        $rules = [
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

        foreach ($rules as [$key, $value, $accepted]) {
            $isValid = match ($key) {
                'fts.fts5.columnsize' => in_array((int) $value, [0, 1], true),
                'fts.fts5.wal' => is_bool($value),
                'fts.fts5.busy_timeout' => is_numeric($value) && (int) $value >= 0,
                default => in_array($value, $accepted, true),
            };

            $this->line('   ' . ($isValid ? '<fg=green>✓</>' : '<fg=red>✗</>') . " {$key} = " . json_encode($value));

            if (! $isValid) {
                $expected = $key === 'fts.fts5.busy_timeout'
                    ? 'must be a non-negative integer'
                    : 'accepted: ' . implode('|', $accepted);
                $this->line("     <fg=yellow>⚠ Expected {$expected}</>");
                $this->allOk = false;
            }
        }
        $this->newLine();
    }

    private function checkOperators(): void
    {
        $this->line('5. FTS5 Operators');
        $rawOps = SqliteFtsEngine::getRawSupportedOperators();
        $allowedOps = SqliteFtsEngine::getSupportedOperators();

        foreach (['AND', 'OR', 'NOT', 'NEAR'] as $op) {
            $sqlite = in_array($op, $rawOps, true);
            $configOk = in_array($op, $allowedOps, true);
            $note = match (true) {
                $configOk => 'SQLite: ✓, Config: allowed',
                $sqlite && ! $configOk => 'SQLite: ✓, Config: restricted',
                default => 'SQLite: ✗, Config: —',
            };
            $this->line('   ' . ($configOk ? '<fg=green>✓</>' : '<fg=red>✗</>') . " {$op} ({$note})");
        }
        $this->newLine();
    }
}
