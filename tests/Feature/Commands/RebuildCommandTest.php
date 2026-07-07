<?php

namespace Moaines\LaravelFts\Tests\Feature\Commands;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Tests\TestCase;

class RebuildCommandTest extends TestCase
{
    public function test_rebuild_no_model_without_force_prompts_confirmation(): void
    {
        $this->artisan('fts:rebuild')
            ->expectsConfirmation('This will rebuild ALL indexed models. Continue?', 'no')
            ->expectsOutput('Rebuild cancelled.')
            ->assertSuccessful();
    }

    public function test_rebuild_with_force_succeeds(): void
    {
        $this->artisan('fts:rebuild --force')
            ->expectsOutput('Rebuild complete.')
            ->assertSuccessful();
    }
}
