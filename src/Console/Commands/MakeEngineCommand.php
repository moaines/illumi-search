<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeEngineCommand extends Command
{
    protected $signature = 'illumi-search:make-engine
        {name : The engine class name (e.g. Algolia)}
        {--quality : Generate test with QualityTestSuite (60+ operator/mode/suggest/ranking tests)}
        {--integration : Generate integration test with AbstractEngineTest (38+ CRUD/edge-case tests)}
        {--all : Generate both quality + integration test files (plus the engine class)}
        {--minimal : Generate only the engine class, no test file}
        {--force : Overwrite all files (engine + tests)}
        {--force-engine : Overwrite only the engine file if it exists}
        {--force-tests : Overwrite only the test files if they exist}';

    protected $description = 'Generate a stub for a custom search engine';

    public function handle(): int
    {
        $name = $this->argument('name');
        $name = Str::studly($name);
        $baseName = str_replace('Engine', '', $name);

        $lower = Str::kebab($baseName);
        $namespace = 'App\\Engines';

        $testNamespace = 'Tests\\Feature\\Engines';

        $stubVars = [
            'DummyNamespace' => $namespace,
            'DummyTestNamespace' => $testNamespace,
            'DummyClass' => $name,
            'dummyclass' => $lower,
            'dummy_class' => $baseName,
        ];

        // Detect conflicting existing engines (same base name, different variants)
        $existing = glob(app_path("Engines/{$baseName}.php")) + glob(app_path("Engines/{$baseName}Engine.php"));
        if (count($existing) > 1) {
            $conflicts = array_diff($existing, [app_path("Engines/{$name}.php")]);
            $this->warn('Existing files for this engine name:');
            foreach ($conflicts as $f) {
                $this->line('  ' . basename($f));
            }
            $this->line('Check for duplicate engine classes before registering.');
        }

        // Force flags
        $forceEngine = $this->option('force-engine') || $this->option('force');
        $forceTests = $this->option('force-tests') || $this->option('force');

        // Generate engine file
        $enginePath = app_path("Engines/{$name}.php");
        if (! $this->writeStub('engine.stub', $enginePath, $stubVars, $forceEngine)) {
            return Command::FAILURE;
        }

        $testsDir = base_path('tests/Feature/Engines');
        if (! is_dir($testsDir)) {
            mkdir($testsDir, 0755, true);
        }

        $isAll = $this->option('all');
        $isQuality = $this->option('quality') || $isAll;
        $isIntegration = $this->option('integration') || $isAll;
        $isMinimal = $this->option('minimal');

        if ($isMinimal) {
            $testLabel = ' (minimal — no test file)';
        } elseif ($isAll) {
            $qPath = "{$testsDir}/{$name}QualityTest.php";
            $iPath = "{$testsDir}/{$baseName}IntegrationTest.php";
            $this->writeStub('engine.test.quality.stub', $qPath, $stubVars, $forceTests);
            $this->writeStub('engine.test.integration.stub', $iPath, $stubVars, $forceTests);
            $testLabel = " ✓ Quality test: {$qPath}\n ✓ Integration test: {$iPath}";
        } elseif ($isQuality) {
            $qPath = "{$testsDir}/{$name}QualityTest.php";
            $this->writeStub('engine.test.quality.stub', $qPath, $stubVars, $forceTests);
            $testLabel = " ✓ Quality test: {$qPath}";
        } elseif ($isIntegration) {
            $iPath = "{$testsDir}/{$baseName}IntegrationTest.php";
            $this->writeStub('engine.test.integration.stub', $iPath, $stubVars, $forceTests);
            $testLabel = " ✓ Integration test: {$iPath}";
        } else {
            $testPath = "{$testsDir}/{$name}Test.php";
            $this->writeStub('engine.test.basic.stub', $testPath, $stubVars, $forceTests);
            $testLabel = " ✓ Basic test: {$testPath}";
        }

        $this->newLine();
        $this->info("✓ Engine created: {$enginePath}");
        $this->line($testLabel);
        $this->newLine();

        $this->line('Next steps:');
        $this->line('1. Open ' . class_basename($enginePath) . ' and implement the // TODO methods');
        $this->line('2. Register your engine in AppServiceProvider:');
        $this->line('');
        $this->line('   use Moaines\IllumiSearch\IllumiSearchServiceProvider;');
        $this->line('');
        $this->line("   IllumiSearchServiceProvider::extend('{$lower}', function (\$app) {");
        $this->line("       return new \\{$namespace}\\{$name}(");
        $this->line("           host: config('illumi-search.engines.{$lower}.host'),");
        $this->line("           apiKey: config('illumi-search.engines.{$lower}.api_key'),");
        $this->line('       );');
        $this->line('   });');
        $this->line('');
        $this->line('3. Add config in config/illumi-search.php:');
        $this->line('');
        $this->line("   '{$lower}' => [");
        $this->line("       'host' => env('ILLUMI_SEARCH_" . strtoupper($lower) . "_HOST', 'http://localhost:7700'),");
        $this->line("       'api_key' => env('ILLUMI_SEARCH_" . strtoupper($lower) . "_KEY', ''),");
        $this->line('   ],');
        $this->line('');
        $this->line("4. Set ILLUMI_SEARCH_DRIVER={$lower} in your .env");
        $this->line('');
        $this->line('See src/Engines/MeilisearchEngine.php for a complete reference implementation.');

        if ($isAll) {
            $this->line('');
            $this->line('Tip: Run the quality tests with: phpunit --filter="' . $name . 'Quality"');
        }

        $this->newLine();

        return Command::SUCCESS;
    }

    private function writeStub(string $stubName, string $targetPath, array $vars, bool $force = false): bool
    {
        if (file_exists($targetPath) && ! $force) {
            $this->warn("Skipped {$targetPath} (use --force or --force-engine/--force-tests to overwrite)");
            return true;
        }

        $stubPath = __DIR__ . '/stubs/' . $stubName;
        if (! file_exists($stubPath)) {
            $this->error("Stub not found: {$stubPath}");
            return false;
        }

        $content = file_get_contents($stubPath);
        $content = str_replace(array_keys($vars), array_values($vars), $content);

        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($targetPath, $content);

        return true;
    }
}
