<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use Moaines\IllumiSearch\Tests\TestCase;

class MakeEngineCommandTest extends TestCase
{
    private string $enginePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enginePath = app_path('Engines/TestEngine.php');
    }

    protected function tearDown(): void
    {
        File::delete($this->enginePath);
        File::deleteDirectory(app_path('Engines'));
        File::deleteDirectory(base_path('tests/Feature/Engines'));
        parent::tearDown();
    }

    public function test_generates_engine_file(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine')
            ->expectsOutputToContain('Engine created')
            ->assertSuccessful();

        $this->assertFileExists($this->enginePath);

        $content = file_get_contents($this->enginePath);
        $this->assertStringContainsString('namespace App\Engines', $content);
        $this->assertStringContainsString('class TestEngine', $content);
        $this->assertStringContainsString('function search(', $content);
        $this->assertStringContainsString('// TODO', $content);
    }

    public function test_generates_basic_test_by_default(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine')
            ->assertSuccessful();

        $path = base_path('tests/Feature/Engines/TestEngineTest.php');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('class TestEngineTest', $content);
        $this->assertStringContainsString('engine_implements_the_contract', $content);
    }

    public function test_quality_flag_generates_quality_test(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine --quality')
            ->assertSuccessful();

        $path = base_path('tests/Feature/Engines/TestEngineQualityTest.php');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('use QualityTestSuite', $content);
        $this->assertStringContainsString('createEngine', $content);
    }

    public function test_integration_flag_generates_integration_test(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine --integration')
            ->assertSuccessful();

        $path = base_path('tests/Feature/Engines/TestIntegrationTest.php');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('engine_implements_the_contract', $content);
        $this->assertStringContainsString('table_operations_create_drop_exists', $content);
    }

    public function test_all_flag_generates_quality_and_integration_tests(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine --all')
            ->assertSuccessful();

        $qualPath = base_path('tests/Feature/Engines/TestEngineQualityTest.php');
        $intPath = base_path('tests/Feature/Engines/TestIntegrationTest.php');

        $this->assertFileExists($qualPath);
        $this->assertFileExists($intPath);

        $qContent = file_get_contents($qualPath);
        $iContent = file_get_contents($intPath);

        $this->assertStringContainsString('use QualityTestSuite', $qContent);
        $this->assertStringContainsString('table_operations_create_drop_exists', $iContent);
    }

    public function test_minimal_flag_skips_test_file(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine --minimal')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(base_path('tests/Feature/Engines/TestEngineTest.php'));
        $this->assertFileDoesNotExist(base_path('tests/Feature/Engines/TestEngineQualityTest.php'));
    }

    public function test_force_flag_overwrites_existing_files(): void
    {
        File::ensureDirectoryExists(app_path('Engines'));
        file_put_contents($this->enginePath, 'old engine');

        $testPath = base_path('tests/Feature/Engines/TestEngineTest.php');
        File::ensureDirectoryExists(base_path('tests/Feature/Engines'));
        file_put_contents($testPath, 'old test');

        $this->artisan('illumi-search:make-engine TestEngine --force')
            ->assertSuccessful();

        $this->assertStringNotContainsString('old engine', file_get_contents($this->enginePath));
        $this->assertStringNotContainsString('old test', file_get_contents($testPath));
    }

    public function test_force_engine_flag_overwrites_only_engine(): void
    {
        File::ensureDirectoryExists(app_path('Engines'));
        file_put_contents($this->enginePath, 'old engine');

        $testPath = base_path('tests/Feature/Engines/TestEngineTest.php');
        File::ensureDirectoryExists(base_path('tests/Feature/Engines'));
        file_put_contents($testPath, 'old test');

        $this->artisan('illumi-search:make-engine TestEngine --force-engine')
            ->assertSuccessful();

        $this->assertStringNotContainsString('old engine', file_get_contents($this->enginePath));
        $this->assertStringContainsString('old test', file_get_contents($testPath));
    }

    public function test_force_tests_flag_overwrites_only_tests(): void
    {
        File::ensureDirectoryExists(app_path('Engines'));
        file_put_contents($this->enginePath, 'old engine');

        $testPath = base_path('tests/Feature/Engines/TestEngineTest.php');
        File::ensureDirectoryExists(base_path('tests/Feature/Engines'));
        file_put_contents($testPath, 'old test');

        $this->artisan('illumi-search:make-engine TestEngine --force-tests')
            ->assertSuccessful();

        $this->assertStringContainsString('old engine', file_get_contents($this->enginePath));
        $this->assertStringNotContainsString('old test', file_get_contents($testPath));
    }

    public function test_shows_next_steps(): void
    {
        $this->artisan('illumi-search:make-engine TestEngine')
            ->expectsOutputToContain('Next steps')
            ->expectsOutputToContain('IllumiSearchServiceProvider::extend')
            ->expectsOutputToContain("ILLUMI_SEARCH_DRIVER=test")
            ->assertSuccessful();
    }
}
