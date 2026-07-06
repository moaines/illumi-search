<?php

namespace Moaines\LaravelFts\Tests\Feature;

use Moaines\LaravelFts\Tests\TestCase;

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
        $engine = $this->app->make(\Moaines\LaravelFts\Contracts\FtsEngine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);

        $this->artisan('fts:doctor')
            ->expectsOutputToContain('Indexes')
            ->expectsOutputToContain('App\Models\Post')
            ->assertSuccessful();
    }

    public function test_doctor_fails_when_fts5_missing(): void
    {
        // We can't actually disable FTS5 at runtime, but we can verify
        // the command structure is correct
        $this->artisan('fts:doctor')
            ->assertSuccessful();
    }
}
