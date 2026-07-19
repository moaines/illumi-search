<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_doctor_reports_missing_database(): void
    {
        $this->artisan('fts:doctor')
            ->expectsOutputToContain('FTS Environment Diagnostics')
            ->expectsOutputToContain('ext-sqlite3')
            ->expectsOutputToContain('ext-intl')
            ->assertSuccessful();
    }

    public function test_doctor_reports_existing_database(): void
    {
        $engine = $this->app->make(\Moaines\IllumiSearch\Contracts\FtsEngine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $this->artisan('fts:doctor')
            ->expectsOutputToContain('Indexes')
            ->expectsOutputToContain('App\Models\Post')
            ->assertSuccessful();
    }

    public function test_doctor_fails_when_fts5_missing(): void
    {
        $this->artisan('fts:doctor')
            ->assertSuccessful();
    }

    public function test_doctor_validates_config_values(): void
    {
        config(['fts.fts5.detail' => 'invalid']);
        config(['fts.fts5.synchronous' => 'INVALID']);
        config(['fts.mode' => 'wrong']);
        config(['fts.fts5.processor' => 'bad']);

        $this->artisan('fts:doctor')
            ->expectsOutputToContain('Config Validation')
            ->expectsOutputToContain('✗')
            ->assertExitCode(1);
    }

    public function test_doctor_reports_valid_config(): void
    {
        config(['fts.fts5.detail' => 'column']);
        config(['fts.fts5.synchronous' => 'NORMAL']);
        config(['fts.mode' => 'basic']);

        $this->artisan('fts:doctor')
            ->expectsOutputToContain('Config Validation')
            ->assertSuccessful();
    }

    public function test_doctor_validates_busy_timeout(): void
    {
        config(['fts.fts5.busy_timeout' => -1]);

        $this->artisan('fts:doctor')
            ->expectsOutputToContain('✗')
            ->assertExitCode(1);
    }
}
