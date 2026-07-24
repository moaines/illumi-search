<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Jobs\DeleteIndexJob;
use Moaines\IllumiSearch\Jobs\IndexModelJob;
use Moaines\IllumiSearch\Searchable;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Author;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Book;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Comment;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class SearchableTraitTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(Engine::class);
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
        config(['illumi-search.indexing.mode' => 'queue']);
        $this->createPostIndex();
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertDispatched(IndexModelJob::class);
    }

    public function test_saved_indexes_directly_in_sync_mode(): void
    {
        config(['illumi-search.indexing.mode' => 'sync']);
        $this->createPostIndex();

        $post = Post::forceCreate(['title' => 'test index', 'body' => 'content']);

        $results = $this->engine->search('test index', [Post::class], 10, withSnippets: false);

        $this->assertCount(1, $results);
    }

    public function test_deleted_dispatches_delete_job(): void
    {
        config(['illumi-search.indexing.mode' => 'queue']);

        $post = $this->createPostSafely(['title' => 'test', 'body' => 'content']);

        Bus::fake();

        $post->delete();

        Bus::assertDispatched(DeleteIndexJob::class);
    }

    public function test_manual_mode_does_nothing(): void
    {
        config(['illumi-search.indexing.mode' => 'manual']);
        Bus::fake();

        Post::forceCreate(['title' => 'test', 'body' => 'content']);

        Bus::assertNotDispatched(IndexModelJob::class);
    }

    public function test_to_document_resolves_simple_attr(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));

        $doc = $book->toSearchDocument();

        $this->assertEquals('Test Book', $doc['title']);
        $this->assertEquals('Content', $doc['body']);
    }

    public function test_to_document_resolves_belongs_to_dot_notation(): void
    {
        $this->createBookTables();
        $author = Author::forceCreate(['name' => 'Jean Dupont']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
            'author_id' => $author->id,
        ]));

        $doc = $book->toSearchDocument();

        $this->assertStringContainsString('Jean Dupont', $doc['author_name']);
    }

    public function test_to_document_resolves_has_many_dot_notation(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));
        Comment::forceCreate(['body' => 'Great read!', 'book_id' => $book->id]);
        Comment::forceCreate(['body' => 'Very helpful', 'book_id' => $book->id]);

        $doc = $book->toSearchDocument();

        $this->assertStringContainsString('Great read!', $doc['comments_body']);
        $this->assertStringContainsString('Very helpful', $doc['comments_body']);
    }

    public function test_to_document_null_relation_returns_empty(): void
    {
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test Book',
            'body' => 'Content',
        ]));

        $doc = $book->toSearchDocument();

        $this->assertEquals('', $doc['author_name']);
    }

    public function test_column_name_sanitizes_dots(): void
    {
        $model = new Book;
        $this->assertEquals('author_name', $model->searchColumnName('author.name'));
    }

    public function test_column_name_sanitizes_arrows(): void
    {
        $model = new Book;
        $this->assertEquals('meta_prop', $model->searchColumnName('meta->prop'));
    }

    public function test_column_name_keeps_plain_names(): void
    {
        $model = new Book;
        $this->assertEquals('title', $model->searchColumnName('title'));
    }

    public function test_to_document_uses_sanitized_keys(): void
    {
        $this->createBookTables();
        $author = Author::forceCreate(['name' => 'Jane']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'My Book',
            'body' => 'Content',
            'author_id' => $author->id,
        ]));

        $doc = $book->toSearchDocument();

        $this->assertArrayNotHasKey('author.name', $doc);
        $this->assertArrayNotHasKey('comments.body', $doc);
        $this->assertArrayHasKey('author_name', $doc);
        $this->assertArrayHasKey('comments_body', $doc);
        $this->assertStringContainsString('Jane', $doc['author_name']);
    }

    public function test_validate_searchable_passes_valid_columns(): void
    {
        $this->createBookTables();
        $book = new Book;

        $warnings = $book->validateSearchable();

        $this->assertEmpty($warnings);
    }

    public function test_validate_searchable_warns_on_missing_relation(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = ['inexistant.col'];
        };

        $warnings = $model->validateSearchable();

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('inexistant', $warnings[0]);
    }

    public function test_has_many_respects_max_related_values(): void
    {
        config(['illumi-search.processing.max_related_values' => 2]);
        $this->createBookTables();
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Test',
            'body' => 'Content',
        ]));
        for ($i = 0; $i < 10; $i++) {
            Comment::forceCreate(['body' => "Comment {$i}", 'book_id' => $book->id]);
        }

        $doc = $book->toSearchDocument();

        $this->assertStringContainsString('Comment 0', $doc['comments_body']);
        $this->assertStringContainsString('Comment 1', $doc['comments_body']);
        $this->assertStringNotContainsString('Comment 9', $doc['comments_body']);
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

        $doc = $book->toSearchDocument();

        $this->assertArrayHasKey('title', $doc);
        $this->assertArrayHasKey('author_name', $doc);
        $this->assertArrayHasKey('comments_body', $doc);
        $this->assertArrayHasKey('fullname', $doc);
        $this->assertEquals('Les Misérables', $doc['title']);
        $this->assertStringContainsString('Victor', $doc['author_name']);
        $this->assertStringContainsString('Classic!', $doc['comments_body']);
    }

    public function test_rebuild_with_dot_notation_indexes_correctly(): void
    {
        config(['illumi-search.indexing.mode' => 'sync']);
        $this->createBookTables();
        $this->engine->createTable(Book::class, ['title', 'body', 'author_name', 'comments_body', 'fullname']);

        $author = Author::forceCreate(['name' => 'Hugo']);
        $book = Book::withoutEvents(fn () => Book::forceCreate([
            'title' => 'Les Misérables',
            'body' => 'Novel',
            'author_id' => $author->id,
        ]));

        $book->syncToSearch($book);
        $results = $this->engine->search('Hugo', [Book::class], 10, withSnippets: false);

        $this->assertNotEmpty($results);
    }

    public function test_relations_returns_single(): void
    {
        $this->createBookTables();
        $book = new Book;

        $relations = $book->relationsForRebuild();

        $this->assertContains('author', $relations);
        $this->assertContains('comments', $relations);
        $this->assertNotContains('fullname', $relations);
    }

    public function test_relations_ignores_plain_columns(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = ['title' => ['weight' => 3]];
        };

        $relations = $model->relationsForRebuild();

        $this->assertEmpty($relations);
    }

    public function test_relations_handles_nested_dot_notation(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = ['book.author.name' => ['weight' => 1]];
        };

        $relations = $model->relationsForRebuild();

        $this->assertEquals(['book.author'], $relations);
    }

    public function test_relations_handles_json_path(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = ['meta->rating' => ['weight' => 1]];
        };

        $relations = $model->relationsForRebuild();

        $this->assertEmpty($relations);
    }

    public function test_relations_deduplicates(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = [
                'author.name' => ['weight' => 1],
                'author.bio' => ['weight' => 1],
            ];
        };

        $relations = $model->relationsForRebuild();

        $this->assertCount(1, $relations);
        $this->assertEquals(['author'], $relations);
    }
}
