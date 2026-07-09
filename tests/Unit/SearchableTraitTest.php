<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Facades\Fts;
use Moaines\LaravelFts\FtsIndexManager;
use Moaines\LaravelFts\Jobs\IndexModelJob;
use Moaines\LaravelFts\Jobs\DeleteIndexJob;
use Moaines\LaravelFts\Tests\TestSupport\Models\Author;
use Moaines\LaravelFts\Tests\TestSupport\Models\Book;
use Moaines\LaravelFts\Tests\TestSupport\Models\Comment;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Tests\TestCase;

class SearchableTraitTest extends TestCase
{
    private \Moaines\LaravelFts\Contracts\FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(\Moaines\LaravelFts\Contracts\FtsEngine::class);
    }

    private function createPostIndex(): void
    {
        $this->engine->createTable(Post::class, ['title', 'body']);
    }

    private function createPostSafely(array $data): Post
    {
        return Post::withoutEvents(fn () => Post::forceCreate($data));
    }

    private function createBookTables(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->text('body')->nullable();
            $table->foreignId('book_id')->nullable()->constrained('books');
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('authors');
            $table->timestamps();
        });
    }

    public function test_saved_dispatches_job_in_queue_mode(): void
    {
        config(['fts.indexing' => 'queue']);
        $this->createPostIndex();
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertDispatched(IndexModelJob::class);
    }

    public function test_saved_indexes_directly_in_sync_mode(): void
    {
        config(['fts.indexing' => 'sync']);
        $this->createPostIndex();

        $post = Post::forceCreate(['title' => 'test index', 'body' => 'content']);

        $results = $this->engine->search('test index', [Post::class], 10, withSnippets: false);

        $this->assertCount(1, $results);
    }

    public function test_deleted_dispatches_delete_job(): void
    {
        config(['fts.indexing' => 'queue']);

        $post = $this->createPostSafely(['title' => 'test', 'body' => 'content']);

        Bus::fake();

        $post->delete();

        Bus::assertDispatched(DeleteIndexJob::class);
    }

    public function test_manual_mode_does_nothing(): void
    {
        config(['fts.indexing' => 'manual']);
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertNotDispatched(IndexModelJob::class);
    }

    public function test_to_fts_document_resolves_simple_attr(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));

        $doc = $book->toFtsDocument();

        $this->assertEquals('Test Book', $doc['title']);
        $this->assertEquals('Content', $doc['body']);
    }

    public function test_to_fts_document_resolves_belongs_to_dot_notation(): void
    {
        $this->createBookTables();
        $author = Author::forceCreate(['name' => 'Jean Dupont']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
            'author_id' => $author->id,
        ]));

        $doc = $book->toFtsDocument();

        $this->assertStringContainsString('Jean Dupont', $doc['author.name']);
    }

    public function test_to_fts_document_resolves_has_many_dot_notation(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));
        Comment::forceCreate(['body' => 'Great read!', 'book_id' => $book->id]);
        Comment::forceCreate(['body' => 'Very helpful', 'book_id' => $book->id]);

        $doc = $book->toFtsDocument();

        $this->assertStringContainsString('Great read!', $doc['comments.body']);
        $this->assertStringContainsString('Very helpful', $doc['comments.body']);
    }

    public function test_to_fts_document_null_relation_returns_empty(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));

        $doc = $book->toFtsDocument();

        $this->assertEquals('', $doc['author.name']);
    }

    public function test_to_fts_document_resolves_virtual_accessor(): void
    {
        $this->createBookTables();
        $author = Author::forceCreate(['name' => 'Jane']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'My Book',
            'body' => 'Content',
            'author_id' => $author->id,
        ]));

        $doc = $book->toFtsDocument();

        $this->assertStringContainsString('My Book by Jane', $doc['fullname']);
    }

    public function test_validate_fts_searchable_passes_valid_columns(): void
    {
        $this->createBookTables();
        $book = new Book;

        $warnings = $book->validateFtsSearchable();

        $this->assertEmpty($warnings);
    }

    public function test_validate_fts_searchable_warns_on_missing_relation(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Moaines\LaravelFts\Searchable;
            protected array $ftsSearchable = ['inexistant.col'];
        };

        $warnings = $model->validateFtsSearchable();

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('inexistant', $warnings[0]);
    }

    public function test_has_many_respects_max_related_values(): void
    {
        config(['fts.max_related_values' => 2]);
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test',
            'body' => 'Content',
        ]));
        for ($i = 0; $i < 10; $i++) {
            Comment::forceCreate(['body' => "Comment {$i}", 'book_id' => $book->id]);
        }

        $doc = $book->toFtsDocument();

        $this->assertStringContainsString('Comment 0', $doc['comments.body']);
        $this->assertStringContainsString('Comment 1', $doc['comments.body']);
        $this->assertStringNotContainsString('Comment 9', $doc['comments.body']);
    }

    public function test_mixed_simple_and_dot_notation(): void
    {
        $this->createBookTables();
        $author = Author::forceCreate(['name' => 'Victor']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Les Misérables',
            'body' => 'Long novel',
            'author_id' => $author->id,
        ]));
        Comment::forceCreate(['body' => 'Classic!', 'book_id' => $book->id]);

        $doc = $book->toFtsDocument();

        $this->assertArrayHasKey('title', $doc);
        $this->assertArrayHasKey('author.name', $doc);
        $this->assertArrayHasKey('comments.body', $doc);
        $this->assertArrayHasKey('fullname', $doc);
        $this->assertEquals('Les Misérables', $doc['title']);
        $this->assertStringContainsString('Victor', $doc['author.name']);
        $this->assertStringContainsString('Classic!', $doc['comments.body']);
    }
}
