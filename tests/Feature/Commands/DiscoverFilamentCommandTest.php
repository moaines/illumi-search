<?php

namespace Moaines\IllumiSearch\Tests\Feature\Commands;

use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Tests\TestCase;

class DiscoverFilamentCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('suggest_books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('genre')->nullable();
            $table->timestamps();
        });
    }

    private function mockFilamentWithResource(string $resourceClass): void
    {
        $panel = $this->createMock(Panel::class);
        $panel->method('getResources')->willReturn([$resourceClass]);

        $this->app->singleton('filament', function () use ($panel) {
            return new class ($panel) {
                public function __construct(private $panel) {}
                public function getCurrentPanel() { return $this->panel; }
                public function getPanel(string $id) { return $this->panel; }
                public function auth() { return auth(); }
                public function getAuthGuard() { return 'web'; }
            };
        });
    }

    private function createMinimalResource(
        string $modelClass,
        string $titleAttr = 'title',
        ?array $globalSearchAttrs = null,
    ): string {
        $code = <<<PHP
            return new class extends \\Filament\\Resources\\Resource {
                protected static ?string \$model = '{$modelClass}';
                protected static ?string \$recordTitleAttribute = '{$titleAttr}';

                public static function getGloballySearchableAttributes(): array
                {
                    return ' . var_export($globalSearchAttrs, true) . ';
                }

                public static function form(\\Filament\\Forms\\Form \$f): \\Filament\\Forms\\Form { return \$f; }
                public static function table(\\Filament\\Tables\\Table \$t): \\Filament\\Tables\\Table { return \$t; }
                public static function getPages(): array { return []; }

                public static function getEloquentQuery(): Builder
                {
                    return ('{$modelClass}')::query();
                }
            };
        PHP;

        return eval($code); // safe in tests
    }

    public function test_falls_back_to_first_panel_when_no_current_panel(): void
    {
        $resource = new class extends Resource
        {
            protected static ?string $model = \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class;
            protected static ?string $recordTitleAttribute = 'title';

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::query();
            }
        };

        $panel = $this->createMock(Panel::class);
        $panel->method('getResources')->willReturn([$resource::class]);
        $panel->method('getId')->willReturn('test-panel');

        $panel2 = $this->createMock(Panel::class);
        $panel2->method('getResources')->willReturn([]);

        $this->app->singleton('filament', function () use ($panel, $panel2) {
            return new class ($panel, $panel2) {
                public function __construct(private $p1, private $p2) {}
                public function getCurrentPanel() { return null; }
                public function getPanels() { return ['admin' => $this->p1, 'test' => $this->p2]; }
                public function getPanel(string $id) { return null; }
            };
        });

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('test-panel')
            ->assertSuccessful();
    }

    public function test_no_resources_returns_no_suggestions(): void
    {
        $panel = $this->createMock(Panel::class);
        $panel->method('getResources')->willReturn([]);

        $this->app->singleton('filament', function () use ($panel) {
            return new class ($panel) {
                public function __construct(private $panel) {}
                public function getCurrentPanel() { return $this->panel; }
                public function getPanel(string $id) { return $this->panel; }
            };
        });

        $this->artisan('illumi-search:discover-filament')
            ->assertSuccessful();
    }

    public function test_no_panel_available(): void
    {
        $this->app->singleton('filament', function () {
            return new class {
                public function getCurrentPanel() { return null; }
                public function getPanels() { return []; }
                public function getPanel(string $id) { return null; }
            };
        });

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('No Filament panel found')
            ->assertSuccessful();
    }

    public function test_suggests_record_title_attribute_when_no_override(): void
    {
        $resource = new class extends Resource
        {
            protected static ?string $model = \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class;
            protected static ?string $recordTitleAttribute = 'title';

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::query();
            }
        };

        $this->mockFilamentWithResource($resource::class);

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('title')
            ->assertSuccessful();
    }

    public function test_suggests_columns_from_override(): void
    {
        $resource = new class extends Resource
        {
            protected static ?string $model = \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class;
            protected static ?string $recordTitleAttribute = 'title';

            public static function getGloballySearchableAttributes(): array
            {
                return ['title', 'body'];
            }

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::query();
            }
        };

        $this->mockFilamentWithResource($resource::class);

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('title')
            ->expectsOutputToContain('body')
            ->assertSuccessful();
    }

    public function test_dot_notation_suggested_for_relation(): void
    {
        Schema::create('suggest_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::table('suggest_books', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->constrained('suggest_authors');
        });

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'suggest_books';
            public $timestamps = true;
            protected $guarded = [];

            public function author()
            {
                return $this->belongsTo(\Moaines\IllumiSearch\Tests\TestSupport\Models\Author::class, 'author_id');
            }
        };

        $resource = new class($model) extends Resource
        {
            protected static ?string $recordTitleAttribute = 'title';
            private static ?string $customModel = null;

            public static function setModel(string $m): void { static::$customModel = $m; }

            public static function getModel(): string
            {
                return static::$customModel ?? parent::getModel();
            }

            public static function getGloballySearchableAttributes(): array
            {
                return ['title', 'author.name'];
            }

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return (new static::$customModel)->newQuery();
            }
        };

        $modelClass = get_class($model);
        $resource::setModel($modelClass);
        $this->mockFilamentWithResource($resource::class);

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('author.name')
            ->assertSuccessful();
    }

    public function test_json_format_output(): void
    {
        $resource = new class extends Resource
        {
            protected static ?string $model = \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class;
            protected static ?string $recordTitleAttribute = 'title';

            public static function getGloballySearchableAttributes(): array
            {
                return ['title', 'body'];
            }

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::query();
            }
        };

        $this->mockFilamentWithResource($resource::class);

        $this->artisan('illumi-search:discover-filament --format=json')
            ->assertSuccessful();
    }

    public function test_shows_code_block_for_missing_columns(): void
    {
        $resource = new class extends Resource
        {
            protected static ?string $model = \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::class;
            protected static ?string $recordTitleAttribute = 'title';

            public static function getGloballySearchableAttributes(): array
            {
                return ['title', 'extra'];
            }

            public static function form(\Filament\Forms\Form $f): \Filament\Forms\Form { return $f; }
            public static function table(\Filament\Tables\Table $t): \Filament\Tables\Table { return $t; }
            public static function getPages(): array { return []; }

            public static function getEloquentQuery(): Builder
            {
                return \Moaines\IllumiSearch\Tests\TestSupport\Models\Post::query();
            }
        };

        $this->mockFilamentWithResource($resource::class);

        $this->artisan('illumi-search:discover-filament')
            ->expectsOutputToContain('$searchable')
            ->expectsOutputToContain("'extra' => ['weight' => 1]")
            ->assertSuccessful();
    }
}
