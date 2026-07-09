# Laravel FTS

[![Tests](https://github.com/moaines/laravel-fts/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/laravel-fts/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Flaravel--fts-28a745?logo=composer)](https://packagist.org/packages/moaines/laravel-fts)

**Full-text search for Laravel using SQLite FTS5 + PHP-intl.**
BM25 ranking, search-as-you-type prefix indexing, multilingual accent folding
(Latin, CJK, Arabic, Cyrillic), per-column weights, boolean operators,
auto-detected operator support with NEAR→AND fallback, spellcheck,
multi-tenant isolation, authorization.
Drop-in `Searchable` trait with queue/sync/lazy batch indexing.
No external services. Just SQLite and PHP.

```bash
composer require moaines/laravel-fts
```

---

## Why?

| | `LIKE %term%` | Laravel FTS |
|---|---|---|
| Relevance ranking | None | BM25 |
| Accent insensitive | No | Yes (intl) |
| Search-as-you-type | No | Prefix indexing |
| Chinese / Japanese / Korean | No | Character-level tokenization |
| Column weighting | No | Per-column weights |
| Performance (10k+ rows) | Table scan | Inverted index |

---

## Requirements

- **PHP** 8.2+
- **SQLite3** extension (with FTS5 support, bundled with PHP 8+)
- **intl** extension (accent folding, CJK, Arabic, Cyrillic)
- **mbstring** extension (multibyte string operations)
- **Local persistent filesystem** — the FTS index is a SQLite file stored on disk. Cloud object storage (S3, GCS, etc.) is **not supported** because SQLite requires random-access writes and file locking that HTTP-based storage cannot provide. The index path defaults to `storage_path('app/fts/fts-index.sqlite')` and must point to a writable local directory.

## Quick Start

### 1. Configure your model

Add the `Searchable` trait and list the columns to index:

```php
use Moaines\LaravelFts\Searchable;

class Post extends Model
{
    use Searchable;

    protected array $ftsSearchable = ['title', 'body'];
}
```

### 2. Build the index

```bash
php artisan fts:rebuild
```

### 3. Search

```php
use Moaines\LaravelFts\Facades\Fts;

$results = Fts::query('laravel')->model(Post::class)->get();
```

---

## Documentation

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Search API](#search-api)
- [Artisan Commands](#artisan-commands)
- [`fts:doctor`](#php-artisan-ftsdoctor)
- [How It Works](#how-it-works)
- [Text Processing](#text-processing)
- [CJK Support](#cjk-support)
- [Spellcheck (Did you mean?)](#spellcheck-did-you-mean)
- [Multi-tenant](#multi-tenant-isolation)
- [Authorization](#authorization)
- [Indexing Strategies](#indexing-strategies)
- [Lazy Rebuild](#lazy-rebuild-batch--queue)
- [Testing](#testing)

---

## Requirements

| Dependency | Required |
|---|---|
| PHP `^8.2` | ✅ |
| `ext-sqlite3` (with FTS5) | ✅ |
| `ext-intl` | ✅ |
| `ext-mbstring` | ✅ |

**FTS5 check** — the package validates at boot:

```php
$db = new SQLite3(':memory:');
$db->exec("CREATE VIRTUAL TABLE _test USING fts5(content)");
```

---

## Installation

```bash
composer require moaines/laravel-fts
```

Laravel auto-discovers the service provider and facade.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=fts-config
```

---

## Configuration

```php
// config/fts.php

return [
    // SQLite index file.
    // - Relative path (default): resolved via storage_path()
    // - Absolute path (starts with /): used as-is for persistent volumes
    // - Cloud storage (S3, GCS) is NOT supported
    'database_path' => env('FTS_DATABASE_PATH', 'app/fts/fts-index.sqlite'),

    // Search mode: 'basic' or 'advanced'
    'mode' => env('FTS_MODE', 'advanced'),

    // Indexing: 'queue', 'sync', or 'manual'
    'indexing' => env('FTS_INDEXING', 'queue'),

    'operators' => [
        'enabled' => null,  // null = auto-detect, or ['AND', 'OR', 'NOT']
    ],

    'fts5' => [
        'prefix_lengths'  => [2, 3, 4],          // search-as-you-type
        'detail'          => 'full',              // 'full', 'column', 'none'
        'automerge'       => 4,                   // segments before auto merge
        'crisismerge'     => 16,                  // segments before forced merge
        'pgsz'            => 1000,                // index page size (bytes)
    ],
];
```

### Operators

Control which FTS5 query operators are allowed in advanced mode.

| Config | Behavior |
|---|---|
| `null` (default) | All operators supported by the SQLite build are available |
| `['AND', 'OR', 'NOT']` | Disable `NEAR` even if SQLite supports it |
| `['AND']` | Only `AND` is allowed; `OR`, `NOT`, `NEAR` treated as regular terms |
| `[]` | All operators disabled — every term is treated as a search keyword |

Operators are auto-detected at runtime. Run `php artisan fts:doctor` to see which operators your SQLite build supports vs. which are enabled by config.

---

## Model Setup

### 1. Minimal

Add the trait and list your columns:

```php
use Moaines\LaravelFts\Searchable;

class Post extends Model
{
    use Searchable;

    protected array $ftsSearchable = ['title', 'body'];
}
```

### 2. With weights

Assign BM25 relevance multipliers to prioritize columns:

```php
protected array $ftsSearchable = [
    'title' => ['weight' => 3],  // 3× importance in ranking
    'body'  => ['weight' => 1],
];
```

Three syntaxes are accepted:

| Syntax | Example | Description |
|---|---|---|
| **Explicit** | `'title' => ['weight' => 3]` | Full configuration with options |
| **Minimal** | `'author'` | Default weight, no options |
| **Shorthand** | `'excerpt' => true` | Same as minimal |

### 3. With dot notation

Search across related model attributes without custom `toFtsDocument()` logic.
Supports `belongsTo`, `hasMany`, and Eloquent accessors:

```php
protected array $ftsSearchable = [
    'title'            => ['weight' => 3],
    'body'             => ['weight' => 1],
    'writer.name'      => ['weight' => 1],   // belongsTo → Writer.name
    'comments.body'    => ['weight' => 1],   // hasMany → Comment.body (concatenated)
    'fullname'         => ['weight' => 2],   // accessor → getFullnameAttribute()
    'comments.meta->rating' => ['weight' => 1], // JSON property on related model
];
```

How each dot-notation variant resolves:

| Notation | Resolution | Example value |
|---|---|---|
| `'writer.name'` | `$book->writer->name` | `'Jean Dupont'` |
| `'comments.body'` | `$book->comments->pluck('body')->implode(' ')` | `'Great! Loved it.'` |
| `'fullname'` | `$book->fullname` (accessor) | `'Les Misérables by Jean Dupont'` |
| `'comments.meta->rating'` | `$book->comments->pluck('meta->rating')` | `'5 4 5'` |

Note: dots (`.`) and arrows (`->`) in column names are automatically converted to underscores (`_`) for the FTS5 index (e.g. `writer.name` becomes `writer_name`). This keeps FTS5 SQL identifiers valid while preserving the full path for value resolution.

When the relation returns a collection (`hasMany`, `belongsToMany`), values are concatenated with a space. The maximum number of related values is controlled by `max_related_values` in config (default: 100). Null relations return an empty string silently.

Relations are eager-loaded during `fts:rebuild` to avoid N+1 queries. Validation warnings are emitted during rebuild for dot-notation columns pointing to non-existent relation methods.

### 3. With locale and snippet

Control the text language and which columns provide context previews:

| Option | Type | Default | Description |
|---|---|---|---|
| `locale` | `string` | `app()->getLocale()` | Language passed to `TextProcessor::process()` |
| `snippet` | `bool` | `true` | If `false`, excluded from result `<mark>` preview |

```php
protected array $ftsSearchable = [
    'title' => ['weight' => 3, 'locale' => 'fr', 'snippet' => false],
    'body'  => ['weight' => 1, 'locale' => 'fr', 'snippet' => true],
    'tags'  => ['snippet' => false],
    'author',
];
```

> Short columns like `title` and `tags` use `snippet: false` to avoid noise in search results.

### 4. Custom document mapping

By default, values are read from model attributes. Override `toFtsDocument()` for computed or relational data:

```php
public function toFtsDocument(): array
{
    return [
        'title'  => $this->title,
        'body'   => strip_tags($this->body),
        'author' => $this->author->name,
    ];
}
```

### 5. Custom TextProcessor

Override the text processing pipeline for this model:

```php
use Moaines\LaravelFts\Contracts\TextProcessor;

class MyCustomProcessor implements TextProcessor
{
    public function process(string $text, string $locale = 'en'): string
    {
        return mb_strtolower(trim($text));
    }
}

class Post extends Model
{
    use Searchable;

    public function ftsTextProcessor(): ?string
    {
        return MyCustomProcessor::class;
    }
}
```

### Other options

```php
protected bool $ftsSyncOnSave = true;  // disable auto-indexing for this model
```

---

## Search API

### Facade

```php
use Moaines\LaravelFts\Facades\Fts;
```

### Basic

```php
$results = Fts::query('bonjour monde')->get();
```

### Filter by model

```php
$results = Fts::query('bonjour')
    ->model(Post::class)
    ->get();

$results = Fts::query('bonjour')
    ->models([Post::class, Comment::class])
    ->get();
```

### Limit and offset

```php
$results = Fts::query('bonjour')
    ->model(Post::class)
    ->limit(10)
    ->offset(20)
    ->get();
```

### Search mode

```php
$results = Fts::query('bonjour')
    ->mode('advanced')  // column-specific, phrase, NEAR, prefix
    ->get();

$results = Fts::query('bonjour')
    ->mode('basic')     // simple keyword + wildcard
    ->get();
```

### Count

```php
$count = Fts::query('bonjour')->count();
```

### Spellcheck (Did you mean?)

Suggest alternative spellings when a search returns few or no results. Uses FTS5 `%_vocab` tables with Levenshtein distance.

```php
use Moaines\LaravelFts\Facades\Fts;

// Returns ['laravel'] for the misspelled query
$suggestions = Fts::didYouMean('laravell');

// Scope to specific models
$suggestions = Fts::didYouMean('developpment', [Post::class]);
// Returns ['developpement']

// Advanced usage with FtsSpellcheck
use Moaines\LaravelFts\FtsSpellcheck;

$spellcheck = app(FtsSpellcheck::class);
$suggestions = $spellcheck
    ->maxDistance(2)          // max Levenshtein distance (default: 2)
    ->maxSuggestions(5)       // max suggestions (default: 5)
    ->suggest('laravell', [Post::class]);
```

The vocab tables are created automatically when `fts:rebuild` runs (alongside each FTS5 table).

### Pagination

```php
$paginator = Fts::query('bonjour')->paginate(15);
```

### Result object

```php
class FtsResult {
    public string $id;          // "App\Models\Post:42"
    public string $modelClass;
    public int|string $modelId;
    public float $rank;         // BM25 score (lower = more relevant)
    public string $title;       // Original title from model
    public ?string $summary;    // Context snippet with <mark> highlighting
    public array $raw;          // All indexed columns
}
```

> The `$model` property gives access to the original Eloquent model attached during search. The model is excluded from `toArray()` and `__sleep()` — it's a transient runtime reference to avoid double queries.
> 
> When using Filament, the Resource's Global Search methods (`getGlobalSearchResultUrl()`, `getGlobalSearchResultDetails()`) take priority over `ftsUrl()` and `ftsCategory()` on the model.

---

## Multi-tenant Isolation

Isolate search indexes per tenant. Each tenant gets its own SQLite file.

### Configuration

```php
// config/fts.php
'tenancy' => [
    'enabled' => env('FTS_TENANCY', false),
    'directory' => 'app/fts/tenants',
],
```

### Setup

Register a resolver closure that returns a unique tenant ID:

```php
// In AppServiceProvider::boot()
use Moaines\LaravelFts\TenantManager;

$this->app->resolving(TenantManager::class, function (TenantManager $manager) {
    $manager->setResolver(fn () => tenant()->id);  // your tenant ID
});
```

The SQLite files are stored in:
```
storage/app/fts/tenants/{tenant_id}/fts-index.sqlite
```

When tenancy is disabled (default), the path is `storage/app/fts/fts-index.sqlite` as usual.

> **Note:** The `FtsEngine` is a singleton within a single request. If you switch tenants mid-request (e.g., in a queue job processing multiple tenants), you must manually clear the engine instance. In a standard HTTP request lifecycle, this is not an issue since the engine is resolved once per request.

> **Note:** The FTS5 vocab tables (used by spellcheck) are updated automatically by FTS5 when data is inserted or modified — no manual sync needed.

---

## Authorization

Filter search results by user permissions using Laravel's Gate/Policy system.

### Enable

```php
$results = Fts::query('laravel')
    ->model(Post::class)
    ->withAuthorization()       // uses Auth::user()
    ->get();

// Or with an explicit user
$results = Fts::query('laravel')
    ->model(Post::class)
    ->withAuthorization($admin)
    ->get();
```

### With a Policy

```php
class PostPolicy
{
    public function view($user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}
```

### With a Closure Gate

```php
Gate::define('view', function ($user, Post $post) {
    return in_array($post->author, ['Jean Dupont', 'Marie Curie'], true);
});

$results = Fts::query('laravel')
    ->model(Post::class)
    ->withAuthorization()
    ->get();
```

### With Spatie `laravel-permission`

Compatible out of the box. Add the `HasRoles` trait to your User model:

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Define a Gate that checks the Spatie permission:

```php
Gate::define('view', function ($user, Post $post) {
    return $user->hasPermissionTo('view posts');
});

$results = Fts::query('laravel')
    ->model(Post::class)
    ->withAuthorization()
    ->get();
```

Or use a Policy with Spatie:

```php
class PostPolicy
{
    public function view($user, Post $post): bool
    {
        return $user->hasRole('editor') || $post->author_id === $user->id;
    }
}
```

### Result flag

Each `FtsResult` has an `authorized` boolean (default `true`). When authorization is enabled, unauthorized results are **removed** from the collection.

---

## Security

### SQLite file protection

The FTS5 index is stored in `storage/app/fts/fts-index.sqlite` by default. Laravel's `storage/` directory is already protected from web access, but ensure:

- The file is **not** committed to version control (it's in `.gitignore` by default)
- File permissions restrict access to the web server user only (`chmod 600`)
- No route or controller exposes the file for download

### Data exposure via snippets

All searchable columns are eligible for context snippets by default. If a column contains sensitive data (PII, internal notes, secrets), disable snippets:

```php
protected array $ftsSearchable = [
    'email'         => ['snippet' => false],
    'internal_note' => ['snippet' => false],
];
```

### Authorization

Results can be filtered through Laravel Policies or Spatie/Shield permissions. See the [Authorization section](#authorization) for details.

### Input handling

- Search queries are normalized (lowercased, diacritics removed) before reaching FTS5
- FTS5 special characters are properly escaped
- Prepared statements prevent SQL injection
- The `$summary` field is sanitized with `strip_tags($summary, '<mark>')` to prevent XSS in result snippets

---

### `php artisan fts:rebuild`

Drop and recreate all FTS5 tables, then repopulate from Eloquent models.

```
Options:
  --model=CLASS     Rebuild specific model(s) only
  --force           Skip confirmation
  --batch-size=N    Index N records now, queue the rest (default: config)
```

### `php artisan fts:sync`

Incremental sync of changed records.

```
Options:
  --model=CLASS  Sync specific model(s)
  --since=DATE   Only records updated after date
```

### `php artisan fts:check`

Detect schema drift between model declarations and index.

```
+---------------------+---------+----------------------+--------+
| Model               | Version | Columns              | Status |
+---------------------+---------+----------------------+--------+
| App\Models\Post     | 1       | title, body          | OK     |
| App\Models\Comment  | 2       | content, author_name | DRIFT  |
+---------------------+---------+----------------------+--------+
```

### `php artisan fts:status`

Index statistics.

```
FTS Database: storage/app/fts/fts-index.sqlite
Size: 12.4 MB
Total indexed records: 6,804

Model                    Records   Last Synced
App\Models\Post          1,234     2026-07-05 12:00:00
App\Models\Comment       5,678     2026-07-05 12:00:00
```

### `php artisan fts:optimize`

Run VACUUM and FTS5 merge optimization to reclaim space and improve performance.

```
FTS Database: storage/app/fts/fts-index.sqlite
Size before: 14.2 MB

Running VACUUM...
Running FTS5 merge optimization...

Size after:  12.4 MB
Space saved: 1.8 MB
Tables optimized: 2
```

### `php artisan fts:doctor`

Diagnose the FTS5 environment — extensions, FTS5 support, database health, configuration, and per-table integrity checks.

The doctor command also runs FTS5's built-in `integrity-check` on each indexed table to detect corruption.

```
🔍 FTS Environment Diagnostics

1. PHP Extensions
 ✓ ext-sqlite3
 ✓ ext-intl
 ✓ ext-mbstring
 ✓ ext-pdo_sqlite

2. SQLite FTS5 Support
 ✓ FTS5 is available (SQLite 3.52.0)

3. FTS Database
 ✓ Path: storage/app/fts/fts-index.sqlite
 ✓ Size: 12.4 MB
 ✓ Readable / Writable

  Indexes:
  - App\Models\Post: 1,234 records

  Integrity:
  ✓ App\Models\Post

4. Configuration
 fts.indexing = queue
 fts.mode = advanced
 ...

5. FTS5 Operators
 ✓ AND
 ✓ OR
 ✓ NOT
 ✗ NEAR

✅ All checks passed
```

### `php artisan fts:suggest`

Analyze Filament panel Resources to suggest `$ftsSearchable` columns for your models.

```bash
php artisan fts:suggest
php artisan fts:suggest --panel=admin
php artisan fts:suggest --format=json
```

The command reads `getGloballySearchableAttributes()` from each Resource. If the Resource does not override this method, it falls back to `$recordTitleAttribute`. Suggestions include a default weight heuristic: the record title attribute gets weight 3, other columns get weight 1.

| Option | Description |
|--------|-------------|
| `--panel` | Filament panel ID (defaults to current panel) |
| `--format` | Output format: `table` (default) or `json` |

Requires Filament to be installed. Gracefully handles missing panels, resources without models, and virtual attributes (columns not found on the model's table).

---

## How It Works

```
┌─────────────────────────────────────────────────────────┐
│                    Your Application                       │
│  ┌───────────────────────────────────────────────────┐  │
│  │  Model with Searchable trait                       │  │
│  │  - saved / deleted events                          │  │
│  │  - toFtsDocument()                                 │  │
│  └──────────────────────┬────────────────────────────┘  │
│                         │                                │
│  ┌──────────────────────▼────────────────────────────┐  │
│  │              SqliteFtsEngine                       │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  storage/app/fts/fts-index.sqlite            │  │  │
│  │  │  ┌───────────────────────────────────────┐  │  │  │
│  │  │  │  idx_posts (FTS5 virtual table)       │  │  │  │
│  │  │  │  idx_comments (FTS5 virtual table)    │  │  │  │
│  │  │  │  _fts_meta (schema tracking)          │  │  │  │
│  │  │  └───────────────────────────────────────┘  │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────┘  │
│                                                          │
│  ┌───────────────────────────────────────────────────┐  │
│  │  UnicodeTextProcessor (intl pipeline)              │  │
│  │  1. strip HTML                                     │  │
│  │  2. Normalizer::FORM_C                             │  │
│  │  3. Remove diacritics (accents → plain)            │  │
│  │  4. Separate CJK characters (开 发 入 门)           │  │
│  │  5. mb_strtolower                                  │  │
│  │  6. Collapse whitespace                            │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

### Indexing flow

```
Model saved → Job dispatched (or sync) → toFtsDocument()
  → TextProcessor::process() → SQLite FTS5 INSERT
```

### Search flow

```
User types query → escapeQuery() → normalizeQuery()
  → FTS5 MATCH → BM25 ranking → enrichWithSnippets()
    → Load original Eloquent models
    → Extract context with <mark> highlighting
    → Return FtsResult[]
```

---

## Text Processing

The `UnicodeTextProcessor` pipeline normalizes text before indexing and queries:

| Step | Effect | Example |
|---|---|---|
| `strip_tags()` | Remove HTML | `<p>Hello</p>` → `Hello` |
| `Normalizer::FORM_C` | Unicode NFC | `é` (NFD) → `é` (NFC) |
| `Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')` | Remove accents | `café` → `cafe`, `façade` → `facade` |
| `separateCjk()` | Space between CJK chars | `开发` → `开 发` |
| `mb_strtolower()` | Lowercase | `Hello` → `hello` |
| `cleanWhitespace()` | Collapse spaces | `a    b` → `a b` |

This ensures that `café`, `cafe`, and `Café` all match the same results.

---

## CJK Support

Chinese, Japanese, and Korean characters are automatically separated by spaces before indexing:

```
开发入门  →  开 发 入 门
안녕하세요  →  안 녕 하 세 요
```

This allows FTS5 to tokenize each character individually. Searching for `开发` matches documents containing both `开` and `发`.

Covered Unicode ranges:
- CJK Unified Ideographs (U+4E00–U+9FFF)
- Extension A (U+3400–U+4DBF)
- Compatibility Ideographs (U+F900–U+FAFF)
- Hiragana (U+3040–U+309F)
- Katakana (U+30A0–U+30FF)
- Hangul Syllables (U+AC00–U+D7AF)

---

## Indexing Strategies

### Queue (default)

On `saved` / `deleted`, dispatch a job to keep the index in sync asynchronously.

```php
// config/fts.php
'indexing' => 'queue',
```

### Sync

Update the index immediately in the same request. Best for small datasets or tests.

```php
'indexing' => 'sync',
```

### Manual

No auto-indexing. Use `php artisan fts:rebuild` and `php artisan fts:sync` explicitly.

```php
'indexing' => 'manual',
```

### Lazy Rebuild (batch + queue)

For large datasets, `fts:rebuild` can index the first N records synchronously and dispatch queue jobs for the rest. This prevents timeouts on big tables while still providing immediate search results for the initial batch.

```env
FTS_REBUILD_BATCH_SIZE=500
```

Or pass `--batch-size` to the command:

```bash
# Index first 500 records now, queue the rest
php artisan fts:rebuild --batch-size=500

# Override config for a specific run
php artisan fts:rebuild --batch-size=1000
```

Output example:

```
  ✓ App\Models\Post: 500 records indexed, 12300 dispatched to queue (total: 12800)
  ✓ App\Models\Comment: 500 records indexed, 8700 dispatched to queue (total: 9200)
```

The queued `IndexBatchJob` jobs process records in chunks of 100 each. Set `FTS_REBUILD_BATCH_SIZE=0` to always index everything synchronously (default).

---

## FTS5 Query Modes

### Basic mode

Simple keyword matching with trailing wildcards:

```
"bonjour"  →  matches "bonjour", "bonjour!", "bonjour-monde"
"bon*"     →  matches "bonjour", "bonsoir", "bonne"
```

### Advanced mode

Full FTS5 query syntax:

| Syntax | Example | Matches |
|---|---|---|
| Single word | `laravel` | documents containing "laravel" |
| Multiple words (AND) | `laravel vuejs` | documents with both terms |
| Column-specific | `title:laravel body:framework` | terms in specific columns |
| Exact phrase | `"base de données"` | exact phrase match |
| Prefix (auto) | `lar` | "laravel", "lar" (thanks to prefix indexing) |
| Boolean | `laravel AND vuejs NOT react` | AND/OR/NOT operators |
| Proximity | `laravel NEAR/5 vuejs` | terms within 5 words¹ |

> ¹ Available operators are auto-detected at runtime. Run `php artisan fts:doctor` to see which are available. Restrict with `config('fts.operators.enabled')` (see [Configuration](#configuration)). NEAR is unsupported in some SQLite builds — when unsupported, it's automatically converted to AND.

---

## Testing

```bash
phpunit                                     # Run all tests
phpunit --testdox                           # Named tests
phpunit tests/Unit/TextProcessorTest.php    # Single test file
phpstan analyse                             # Static analysis
pint                                        # Code style
```

---

## Package Structure

```
laravel-fts/
├── config/fts.php
├── src/
│   ├── Contracts/          # FtsEngine, TextProcessor interfaces
│   ├── Engines/            # SqliteFtsEngine
│   ├── Text/               # UnicodeTextProcessor
│   ├── Console/Commands/   # rebuild, sync, check, status
│   ├── Jobs/               # IndexModelJob, DeleteIndexJob
│   ├── Facades/Fts.php
│   ├── FtsQueryBuilder.php
│   ├── FtsResult.php
│   ├── FtsIndexManager.php
│   ├── Searchable.php      # Eloquent trait
│   └── Exceptions/
└── tests/
```

---

## Limitations

- **Cloud object storage not supported.** The FTS5 index is a SQLite database file and must reside on a local filesystem. S3, GCS, or any HTTP-based storage driver cannot be used — `database_path` always resolves to a local path. See [Requirements](#requirements).
- **Ephemeral environments.** On serverless platforms (Laravel Vapor, Kubernetes without persistent volumes), the index file is lost on redeploy. Use an absolute `FTS_DATABASE_PATH` pointing to a mounted persistent volume, or re-run `php artisan fts:rebuild` after each deploy.
- **Multi-server setups.** SQLite handles concurrent reads well but does not support concurrent writes from multiple processes over NFS. Use a single-writer strategy or consider an external search service for horizontal scaling.

---

## Changelog

### Unreleased

### v1.9.0

- **FTS5 detail option.** New `fts.fts5.detail` config (`full`, `column`, or `none`). `column` saves ~30% index space if NEAR/phrase queries are not needed.
- **Merge tuning.** New `fts.fts5.automerge`, `fts.fts5.crisismerge`, and `fts.fts5.pgsz` config options for fine-grained control over index segment merging and page size.
- **`integrityCheck()`.** New method on `FtsEngine` interface. Performs FTS5 integrity-check on each indexed table.
- **`fts:doctor` integrity checks.** The doctor command now shows per-table integrity status (✅ or ❌).
- **Optimized `enrichWithSnippets()`.** Snippet loading now uses `SELECT` only for columns declared in `$ftsSearchable`, eager-loads relations for dot-notation columns, and detects virtual attributes via `Schema::hasColumn()`.

### v1.8.0

- **Snippet fix for dot-notation columns.** When a term matches in a relation (e.g. `comments.body`), the snippet now extracts text from the correct column via `resolveFtsValue()`. Chooses the column that actually contains the search term.
- **Orphaned meta cleanup.** `getIndexStats()` no longer crashes on orphaned meta entries. Cleans them up automatically.
- **`resolveFtsValue()` made public.** Allows the engine to resolve dot-notation values for snippets.

### v1.7.0

- **Real-time progress bars.** `fts:rebuild` and `fts:sync` now display a per-model progress bar with current/max count and elapsed time.
- **Eager-loaded relations.** Dot-notation columns (`writer.name`, `comments.body`) are now eager-loaded during chunks, eliminating N+1 queries on rebuild and sync.

### v1.6.0

- **Column name sanitization.** Dots (`.`), arrows (`->`), and dashes (`-`) in `$ftsSearchable` column names are now automatically converted to underscores (`_`) for FTS5. `'comments.body'` becomes `comments_body` in the index — FTS5 no longer rejects them.
- **`ftsColumnName()` helper** on the Searchable trait.
- **`sanitizeDocumentKeys()`** in the engine ensures all document keys are valid SQL identifiers.

### v1.5.0

- **Auto-cleanup orphaned tables.** `fts:rebuild` now removes index tables for models that no longer use the `Searchable` trait.
- **New methods on `FtsEngine` interface:** `tableName()`, `listIndexTables()`, `dropIndexTable()`.

### v1.4.2

- **`fts:suggest` shows PHP code block.** When columns are missing, the command now displays a copy-paste ready `$ftsSearchable` snippet for each model.

### v1.4.1

- **`fts:suggest` CLI fallback.** Falls back to the first available panel when `getCurrentPanel()` returns null (running in CLI).

### v1.4.0

- **`fts:suggest` command.** Analyzes Filament panel Resources and suggests `$ftsSearchable` columns with heuristic weights. Falls back to `$recordTitleAttribute` when `getGloballySearchableAttributes()` is null. Handles dot notation, virtual attributes, and missing panels gracefully. Outputs table or JSON.

### v1.3.0

- **Dot notation in `ftsSearchable`.** Columns like `'writer.name'` and `'comments.body'` auto-resolve related model attributes. Supports `belongsTo`, `hasMany`, `belongsToMany`, and Eloquent accessors. Null-safe, collection-safe, limited by `max_related_values` (default: 100).
- **`validateFtsSearchable()`.** Emits warnings during `fts:rebuild` for dot-notation columns referencing non-existent relations.

### v1.2.0

- **Absolute database path.** `FTS_DATABASE_PATH` starting with `/` is used as-is (for persistent volumes on Vapor/K8s). Relative paths still resolve via `storage_path()`.
- **`--vacuum` flag.** `php artisan fts:rebuild --vacuum` runs VACUUM after rebuilding. Without the flag, VACUUM is skipped for faster rebuilds.
- **`fts:doctor` improvements.** Displays path type (absolute/relative) and free disk space.
- **Optional snippets.** `search()` accepts `$withSnippets` parameter. Set to `false` to skip Eloquent model loading when snippets are not needed.
- **Model discovery cache.** `discoverModels()` caches results in memory within the same request — no redundant filesystem scans.
- **Events.** `RebuildComplete` and `ModelIndexed` events are dispatched during rebuild.
- **Batch jobs use `where(>)` instead of `offset()`.** No more shift on record deletion between queue jobs.
- **`sync()` respects custom timestamps.** Uses `$model->getUpdatedAtColumn()` instead of hardcoded `updated_at`.
- **Operator reset.** `SqliteFtsEngine::resetOperators()` allows resetting operator state between tests (avoids static state leakage).
- **`FtsSpellcheck` per-instance.** Changed from `singleton` to `bind` — `maxDistance`/`maxSuggestions` no longer leak between callers.
- **Removed unused `--mode` option** from `fts:rebuild` command.
- **`escapeQuery()` with cache.** Repeated calls with the same query + mode reuse the cached result.
- **Deduplicated `extractTerms()`** — now shared via `HasQueryTerms` trait.
- **Fixed `normalize()` dead branch.** `Normalizer::normalize()` failure now returns the original text instead of an empty string.

---

## License

MIT
