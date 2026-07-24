<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\IllumiSearchHelper;
use PHPUnit\Framework\TestCase;

class IllumiSearchHelperTest extends TestCase
{
    /** @test */
    public function normalize_column_name_replaces_dots(): void
    {
        $this->assertEquals('comments_body', IllumiSearchHelper::normalizeColumnName('comments.body'));
    }

    /** @test */
    public function normalize_column_name_replaces_arrows(): void
    {
        $this->assertEquals('writer_name', IllumiSearchHelper::normalizeColumnName('writer->name'));
    }

    /** @test */
    public function normalize_column_name_replaces_dashes(): void
    {
        $this->assertEquals('some_column', IllumiSearchHelper::normalizeColumnName('some-column'));
    }

    /** @test */
    public function normalize_column_name_does_not_change_simple_names(): void
    {
        $this->assertEquals('title', IllumiSearchHelper::normalizeColumnName('title'));
    }

    /** @test */
    public function normalize_column_name_handles_mixed(): void
    {
        $this->assertEquals('comments_body', IllumiSearchHelper::normalizeColumnName('comments->body'));
        $this->assertEquals('user_profile_name', IllumiSearchHelper::normalizeColumnName('user.profile-name'));
    }

    /** @test */
    public function model_dir_name_converts_namespace(): void
    {
        $this->assertEquals('app_models_post', IllumiSearchHelper::modelDirName('App\Models\Post'));
    }

    /** @test */
    public function model_dir_name_handles_special_chars(): void
    {
        $this->assertEquals('app_models_testmodel_v2', IllumiSearchHelper::modelDirName('App\Models\Test-Model_V2'));
    }

    /** @test */
    public function model_dir_name_lowercases(): void
    {
        $this->assertEquals('app_models_myclass', IllumiSearchHelper::modelDirName('App\Models\MyClass'));
    }
}
