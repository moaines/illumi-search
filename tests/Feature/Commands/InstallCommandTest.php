<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Moaines\IllumiSearch\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_command_signature_includes_meilisearch(): void
    {
        $command = $this->app->make(\Moaines\IllumiSearch\Console\Commands\InstallCommand::class);
        $ref = new \ReflectionClass($command);

        $engines = $ref->getConstant('ENGINES');
        $this->assertArrayHasKey('meilisearch', $engines);
        $this->assertSame('meilisearch-php', $engines['meilisearch']['ext']);
        $this->assertSame('Unlimited', $engines['meilisearch']['max_docs']);
    }

    public function test_recommendations_include_meilisearch(): void
    {
        $command = $this->app->make(\Moaines\IllumiSearch\Console\Commands\InstallCommand::class);
        $ref = new \ReflectionClass($command);

        $recs = $ref->getConstant('RECOMMENDATIONS');
        $this->assertArrayHasKey('meilisearch', $recs['small']);
        $this->assertArrayHasKey('meilisearch', $recs['medium']);
        $this->assertArrayHasKey('meilisearch', $recs['large']);
    }

    public function test_install_commands_include_meilisearch_composer_hint(): void
    {
        $command = $this->app->make(\Moaines\IllumiSearch\Console\Commands\InstallCommand::class);
        $ref = new \ReflectionClass($command);

        $cmds = $ref->getConstant('INSTALL_CMDS');
        $this->assertArrayHasKey('meilisearch-php', $cmds['Linux']);
        $this->assertStringContainsString('composer', $cmds['Linux']['meilisearch-php']);
    }

    public function test_storage_directories_are_defined(): void
    {
        $command = $this->app->make(\Moaines\IllumiSearch\Console\Commands\InstallCommand::class);
        $ref = new \ReflectionClass($command);

        $dirs = $ref->getConstant('STORAGE_DIRS');
        $this->assertNotEmpty($dirs);
    }
}
