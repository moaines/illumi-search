<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_doctor_reports_missing_database(): void
    {
        $this->artisan('illumi-search:doctor')
            ->expectsOutputToContain('FTS Environment Diagnostics')
            ->expectsOutputToContain('ext-sqlite3')
            ->expectsOutputToContain('ext-intl')
            ->assertSuccessful();
    }

    public function test_doctor_reports_existing_database(): void
    {
        $engine = $this->app->make(\Moaines\IllumiSearch\Contracts\Engine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $this->artisan('illumi-search:doctor')
            ->expectsOutputToContain('Indexes')
            ->expectsOutputToContain('App\Models\Post')
            ->assertSuccessful();
    }

    public function test_doctor_fails_when_fts5_missing(): void
    {
        $this->artisan('illumi-search:doctor')
            ->assertSuccessful();
    }

    public function test_doctor_validates_config_values(): void
    {
        config(['illumi-search.fts5.detail' => 'invalid']);
        config(['illumi-search.fts5.synchronous' => 'INVALID']);
        config(['illumi-search.mode' => 'wrong']);
        config(['illumi-search.fts5.processor' => 'bad']);

        $this->artisan('illumi-search:doctor')
            ->expectsOutputToContain('Config Validation')
            ->expectsOutputToContain('✗')
            ->assertExitCode(1);
    }

    public function test_doctor_reports_valid_config(): void
    {
        config(['illumi-search.fts5.detail' => 'column']);
        config(['illumi-search.fts5.synchronous' => 'NORMAL']);
        config(['illumi-search.mode' => 'basic']);

        $this->artisan('illumi-search:doctor')
            ->expectsOutputToContain('Config Validation')
            ->assertSuccessful();
    }

    public function test_doctor_validates_busy_timeout(): void
    {
        config(['illumi-search.fts5.busy_timeout' => -1]);

        $this->artisan('illumi-search:doctor')
            ->expectsOutputToContain('✗')
            ->assertExitCode(1);
    }
}
