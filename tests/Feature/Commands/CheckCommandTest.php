<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Book;

class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['illumi-search.model_paths' => [__DIR__ . '/../../TestSupport/Models']]);

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_check_reports_ok_for_model_with_dot_notation(): void
    {
        $engine = app(Engine::class);
        $engine->createTable(Book::class, ['title', 'body', 'author_name', 'comments_body', 'fullname']);

        $this->artisan('illumi-search:check')
            ->doesntExpectOutputToContain('DRIFT')
            ->assertSuccessful();
    }

    public function test_check_reports_missing_when_no_index(): void
    {
        $this->artisan('illumi-search:check')
            ->expectsOutputToContain('MISSING')
            ->assertSuccessful();
    }
}
