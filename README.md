# Illumi Search

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.1%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

<p align="center">
    <img src="art/banner_1024x640.png" alt="Illumi Search" width="800">
</p>

**Full-text search for Laravel using PHP's `ext-sqlite3` (bundled with PHP) with FTS5 support + optional `ext-intl`.**
BM25 ranking, search-as-you-type prefix indexing, multilingual accent folding
(Latin, CJK, Arabic, Cyrillic), per-column weights, boolean operators,
auto-detected operator support with NEAR→AND fallback, spellcheck,
multi-tenant isolation, authorization.
Drop-in `Searchable` trait with queue/sync/lazy batch indexing.
No external services. Just SQLite and PHP.
Available in most hosting environments — FTS5 is bundled with PHP.

```bash
composer require moaines/illumi-search
```

---

## Why?

| | `LIKE %term%` | Illumi Search |
|---|---|---|
| Relevance ranking | None | BM25 |
| Accent insensitive | No | Yes (intl) |
| Search-as-you-type | No | Prefix indexing |
| Chinese / Japanese / Korean | No | Character-level tokenization |
| Column weighting | No | Per-column weights |
| Performance (10k+ rows) | Table scan | Inverted index |
| PHP extension required | None | `ext-sqlite3` + `ext-mbstring` (both bundled with PHP) |
| Hosting compatibility | Any | ✅ Almost anywhere Laravel runs — PHP bundles ext-sqlite3 and ext-mbstring. `ext-intl` is optional (fallback processor handles basic accents).

---

## Requirements

- **PHP** 8.1+
- **SQLite3** extension (with FTS5 support, bundled with PHP 8+)
- **intl** extension *(optional, recommended)* — full Unicode normalization and advanced accent folding. Without it, a Symfony-based fallback processor handles basic accents.
- **mbstring** extension (multibyte string operations)
- **Optional:** [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) (adds an FTS5 queries tab)
- **Local persistent filesystem** — the FTS index is a SQLite file stored on disk. Cloud object storage (S3, GCS, etc.) is **not supported** because SQLite requires random-access writes and file locking that HTTP-based storage cannot provide. The index path defaults to `storage_path('app/search/search-index.sqlite')` and must point to a writable local directory.

> ⚠️ **FTS5 required** — `ext-sqlite3` is bundled with PHP, but some minimal Docker images or
> older SQLite builds may not have FTS5 enabled. Run `php artisan illumi-search:doctor` to verify.
> If FTS5 is missing, rebuild PHP/SQLite with `--enable-fts5` or `SQLITE_ENABLE_FTS5`.
> Most Linux distributions: `apt install php-sqlite3` or `yum install php-sqlite3`.

## Quick Start

### 1. Configure your model

Add the `Searchable` trait and list the columns to index:

```php
use Moaines\IllumiSearch\Searchable;

class Post extends Model
{
    use Searchable;

    protected array $searchable = ['title', 'body'];
}
```

### 2. Build the index

```bash
php artisan illumi-search:rebuild
```

### 3. Search

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

$results = IllumiSearch::query('laravel')->model(Post::class)->get();
```

---

## Documentation

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Search (PHP)](#search-php)
- [REST API](#rest-api)
- [Artisan Commands](#artisan-commands)
- [`illumi-search:doctor`](#illumi-searchdoctor)
- [How It Works](#how-it-works)
- [Text Processing](#text-processing)
- [CJK Support](#cjk-support)
- [Spellcheck (Did you mean?)](#spellcheck-did-you-mean)
- [Multi-tenant](#multi-tenant-isolation)
- [Authorization](#authorization)
- [Indexing Strategies](#indexing-strategies)
- [Lazy Rebuild](#lazy-rebuild-batch--queue)
- [Diagnostics](#diagnostics)
- [Package Structure](#package-structure)
- [Testing](#testing)

---

## Requirements

| Dependency | Required |
|---|---|
| PHP `^8.2` | ✅ |
| `ext-sqlite3` (with FTS5) | ✅ |
| `ext-mbstring` | ✅ |
| `ext-intl` (optional) | ✅ — fallback processor active if missing |
| `ext-mbstring` | ✅ |

**FTS5 check** — the package validates at boot:

```php
$db = new SQLite3(':memory:');
$db->exec("CREATE VIRTUAL TABLE _test USING fts5(content)");
```

### Compatibility check

Run this in your terminal before installing to verify your environment:

```bash
php -r "
echo 'SQLite3 extension: ' . (extension_loaded('sqlite3') ? '✅ active' : '❌ NOT loaded') . PHP_EOL;
echo 'intl extension: ' . (extension_loaded('intl') ? '✅ active' : '❌ NOT loaded') . PHP_EOL;
echo 'mbstring extension: ' . (extension_loaded('mbstring') ? '✅ active' : '❌ NOT loaded') . PHP_EOL;
if (extension_loaded('sqlite3')) {
    \$db = new SQLite3(':memory:');
    echo 'SQLite3 version: ' . \$db->version()['versionString'] . PHP_EOL;
    try {
        \$db->exec('CREATE VIRTUAL TABLE _test USING fts5(content)');
        echo 'FTS5: ✅ available' . PHP_EOL;
    } catch (\Exception \$e) {
        echo 'FTS5: ❌ NOT available — ' . \$e->getMessage() . PHP_EOL;
    }
}
"
```

If everything shows ✅, you're ready to install.

---

## Installation

```bash
composer require moaines/illumi-search
```

Laravel auto-discovers the service provider and facade.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=illumi-search-config
```

---

## Configuration

### Environment variables

| Env | Config key | Default | Possible values |
|-----|-----------|---------|----------------|
| `ILLUMI_SEARCH_DATABASE_PATH` | `database_path` | `app/search/search-index.sqlite` | Relative to `storage_path()`, or absolute (starts with `/`) |
| `ILLUMI_SEARCH_MODE` | `mode` | `advanced` | `basic` (simple wildcards), `advanced` (boolean, phrase, NEAR) |
| `ILLUMI_SEARCH_INDEXING` | `indexing` | `queue` | `queue` (async via jobs), `sync` (immediate), `manual` (commands only) |
| `ILLUMI_SEARCH_QUEUE_CONNECTION` | `queue_connection` | `null` (default queue) | Any queue name (`sync`, `redis`, `database`, etc.) |
| `ILLUMI_SEARCH_REBUILD_BATCH_SIZE` | `rebuild_batch_size` | `0` (all sync) | `500`, `1000` — batch size before switching to queue jobs |
| — | `max_results` | `50` | Any positive integer |
| — | `model_paths` | `[app_path('Models')]` | Array of paths to scan for Searchable models |
| `ILLUMI_SEARCH_PROCESSOR` | `fts5.processor` | `unicode` | `unicode` (default), `stemming` (multi-language stemming) |
| — | `fts5.tokenizer` | `unicode61` | `unicode61`, `ascii`, `porter`, `trigram`, `porter unicode61` |
| — | `fts5.prefix_lengths` | `[2, 3, 4]` | E.g. `[2, 3, 4]` for prefix indexes |
| `ILLUMI_SEARCH_COLUMNSIZE` | `fts5.columnsize` | `1` | `1` (default), `0` (omit column sizes, saves ~10% space) |
| — | `fts5.detail` | `full` | `full` (default), `column` (+NEAR +phrase), `none` (term only) |
| — | `fts5.automerge` | `4` | Segments before auto‑merge (0 = disable) |
| — | `fts5.crisismerge` | `16` | Segments before forced merge |
| — | `fts5.pgsz` | `1000` | Index page size in bytes |
| `ILLUMI_SEARCH_WAL` | `fts5.wal` | `true` | `true`, `false` (disable only on NFS) |
| `ILLUMI_SEARCH_CACHE_SIZE_KB` | `fts5.cache_size_kb` | `-64000` (64 MB) | Positive = pages, negative = kilobytes |
| `ILLUMI_SEARCH_SYNCHRONOUS` | `fts5.synchronous` | `NORMAL` | `NORMAL` (fast, safe with WAL), `FULL` (safest, slowest) |
| `ILLUMI_SEARCH_TEMP_STORE` | `fts5.temp_store` | `MEMORY` | `MEMORY` (fast), `FILE` (safe for low RAM) |
| `ILLUMI_SEARCH_BUSY_TIMEOUT` | `fts5.busy_timeout` | `15000` | Milliseconds (0 = no timeout, 5000–15000 recommended) |
| `ILLUMI_SEARCH_MMAP_SIZE` | `fts5.mmap_size` | `0` (disabled) | 0 = off, `67108864` (64 MB), `268435456` (256 MB). ⚠️ Incompatible with NFS/Docker mounts |
| `ILLUMI_SEARCH_AUTHORIZATION` | `authorization.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_TENANCY` | `tenancy.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_TENANCY_DIRECTORY` | `tenancy.directory` | `app/search/tenants` | Relative to `storage_path()` |
| `ILLUMI_SEARCH_SPELLCHECK_VOCAB_LIMIT` | `spellcheck.vocab_limit` | `1000` | Max terms loaded from vocab table |
| — | `operators.enabled` | `null` | `null` (auto‑detect), `['AND', 'OR', 'NOT']`, `[]` |
| — | `max_related_values` | `100` | Max related model values for dot‑notation columns |

### Operators

Control which FTS5 query operators are allowed in advanced mode.

| Config | Behavior |
|---|---|
| `null` (default) | All operators supported by the SQLite build are available |
| `['AND', 'OR', 'NOT']` | Disable `NEAR` even if SQLite supports it |
| `['AND']` | Only `AND` is allowed; `OR`, `NOT`, `NEAR` treated as regular terms |
| `[]` | All operators disabled — every term is treated as a search keyword |

Operators are auto-detected at runtime. Run `php artisan illumi-search:doctor` to see which operators your SQLite build supports vs. which are enabled by config.

### Publish config

```bash
php artisan vendor:publish --tag=illumi-search-config
```

This publishes `config/illumi-search.php` where you can override any setting. Use `.env` variables for values that differ between environments (local, staging, production).

```bash
php artisan vendor:publish --tag=illumi-search-config
```

### 2. With weights

Assign BM25 relevance multipliers to prioritize columns:

```php
protected array $searchable = [
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

Search across related model attributes without custom `toSearchDocument()` logic.
Supports all Eloquent relationships (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `morphOne`, `morphMany`, `morphTo`) and custom accessors:

```php
protected array $searchable = [
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

Relations are eager-loaded during `illumi-search:rebuild` to avoid N+1 queries. Validation warnings are emitted during rebuild for dot-notation columns pointing to non-existent relation methods.

### 4. With locale and snippet

Control the text language and which columns provide context previews:

| Option | Type | Default | Description |
|---|---|---|---|
| `locale` | `string` | `app()->getLocale()` | Language passed to `TextProcessor::process()` |
| `snippet` | `bool` | `true` | If `false`, excluded from result `<mark>` preview |

```php
protected array $searchable = [
    'title' => ['weight' => 3, 'locale' => 'fr', 'snippet' => false],
    'body'  => ['weight' => 1, 'locale' => 'fr', 'snippet' => true],
    'tags'  => ['snippet' => false],
    'author',
];
```

> Short columns like `title` and `tags` use `snippet: false` to avoid noise in search results.

### 4. Custom document mapping

By default, values are read from model attributes. Override `toSearchDocument()` for computed or relational data:

```php
public function toSearchDocument(): array
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
use Moaines\IllumiSearch\Contracts\TextProcessor;

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

    public function searchTextProcessor(): ?string
    {
        return MyCustomProcessor::class;
    }
}
```

### Other options

```php
protected bool $syncOnSave = true;  // disable auto-indexing for this model
```

#### `searchColumnName(string $column): string`

Get the sanitized FTS5 column name. Dots (`.`), arrows (`->`), and dashes (`-`) are converted to underscores (`_`):

```php
$model->searchColumnName('writer.name');  // 'writer_name'
$model->searchColumnName('comments.body'); // 'comments_body'
```

#### `resolveSearchValue(string $column): string`

Resolve a dot-notation column to its actual value on the model instance, traversing relations:

```php
$model->resolveSearchValue('writer.name');      // 'Jean Dupont'
$model->resolveSearchValue('comments.body');    // 'Great book! Loved it. ...'
```

---

## Search (PHP)

### Facade

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;
```

### Basic

```php
$results = IllumiSearch::query('bonjour')->get();
```

### Filter by model

```php
$results = IllumiSearch::query('bonjour')
    ->model(Post::class)
    ->get();

$results = IllumiSearch::query('bonjour')
    ->models([Post::class, Comment::class])
    ->get();
```

### Limit and offset

```php
$results = IllumiSearch::query('bonjour')
    ->model(Post::class)
    ->limit(10)
    ->offset(20)
    ->get();
```

### Search mode

```php
$results = IllumiSearch::query('bonjour')
    ->mode('advanced')  // column-specific, phrase, NEAR, prefix
    ->get();

$results = IllumiSearch::query('bonjour')
    ->mode('basic')     // simple keyword + wildcard
    ->get();
```

### Count

```php
$count = IllumiSearch::query('bonjour')->count();
```

### Spellcheck (Did you mean?)

Suggest alternative spellings when a search returns few or no results. Uses FTS5 `%_vocab` tables with Levenshtein distance.

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

// Returns ['laravel'] for the misspelled query
$suggestions = IllumiSearch::didYouMean('laravell');

// Scope to specific models
$suggestions = IllumiSearch::didYouMean('developpment', [Post::class]);
// Returns ['developpement']

// Advanced usage with Spellcheck
use Moaines\IllumiSearch\Spellcheck;

$spellcheck = app(Spellcheck::class);
$suggestions = $spellcheck
    ->maxDistance(2)          // max Levenshtein distance (default: 2)
    ->maxSuggestions(5)       // max suggestions (default: 5)
    ->suggest('laravell', [Post::class]);
```

The vocab tables are created automatically when `illumi-search:rebuild` runs (alongside each FTS5 table).

### Pagination

```php
$paginator = IllumiSearch::query('bonjour')->paginate(15);
```

### Result object

```php
class Result {
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
> When using Filament, the Resource's Global Search methods (`getGlobalSearchResultUrl()`, `getGlobalSearchResultDetails()`) take priority over `searchUrl()` and `searchCategory()` on the model.

### Laravel Scout

This package does **not** provide a Scout driver — the built-in `Searchable` trait + `IllumiSearch` facade cover most search use cases without Scout's overhead.

If you need Scout integration (for compatibility with existing code), create a custom engine:

```php
namespace App\Engines;

use Laravel\Scout\Engines\Engine;
use Moaines\IllumiSearch\Facades\IllumiSearch;

class IllumiSearchScoutEngine extends Engine
{
    public function search(Builder $builder)
    {
        $results = IllumiSearch::query($builder->query)
            ->model($builder->model)
            ->get();

        return collect($results->pluck('model_id'));
    }

    public function mapIds($results) { /* ... */ }
    public function map(Builder $builder, $results, $model) { /* ... */ }
    public function paginate(Builder $builder, $perPage, $page) { /* ... */ }
    public function delete($models) { /* ... */ }
    // ... other required methods
}
```

Register in `AppServiceProvider`:

```php
use Laravel\Scout\EngineManager;

$this->app->make(EngineManager::class)->extend('illumi-search', function () {
    return new \App\Engines\IllumiSearchScoutEngine;
});
```

Set `SCOUT_DRIVER=illumi-search` in your `.env`.

---

## REST API

Search endpoint for headless apps, mobile apps, or programmatic access.

### Enable

```env
ILLUMI_SEARCH_API_ENABLED=true
```

### Endpoint

```
GET /api/search
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | ✅ | — | Search query (max 200 chars) |
| `models` | string/array | ❌ | All indexed models | Comma-separated `?models=Post,Comment` or array `?models[]=Post&models[]=Comment` |
| `limit` | int | ❌ | 10 | Max results (max 50) |
| `mode` | string | ❌ | `advanced` | `basic`, `advanced`, `raw` |
| `suggest` | bool | ❌ | `false` | Include spellcheck suggestions when no results |

### Example

```bash
curl "/api/search?q=laravel&models=Post,Comment&limit=5&suggest=1"
```

### Response

```json
{
    "results": [
        {
            "id": "records:...",
            "title": "Laravel Testing",
            "url": "/admin/posts/1/edit",
            "summary": "... <mark>Laravel</mark> ..."
        }
    ],
    "total": 1,
    "suggestions": ["laravel", "laravel framework"]
}
```

### Rate limiting

Default: 30 requests per minute. Configure with `ILLUMI_SEARCH_API_RATE_LIMIT` env or `config('illumi-search.api.rate_limit')`.

---

## Diagnostics

Inspect the FTS5 engine version, current PRAGMAs, run integrity checks, and read/write custom metadata.

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;
use Moaines\IllumiSearch\Contracts\Engine;

$engine = app(Engine::class);

// FTS5 availability
echo $engine->isFts5Available() ? '✅ FTS5 ready' : '❌ FTS5 missing';  // bool

// Engine version
echo $engine->getEngineVersion();           // "SQLite 3.46.0 | FTS5"

// Read-only PRAGMAs
echo $engine->getPragma('journal_mode');    // "wal"
echo $engine->getPragma('cache_size');      // -64000
echo $engine->getPragma('busy_timeout');    // 15000
echo $engine->getPragma('page_size');       // 4096
echo $engine->getPragma('page_count');      // 12345

// Full integrity check across all FTS5 tables
$result = $engine->fullIntegrityCheck();
// ['passed' => true, 'errors' => []]

// Persistent config storage (stored in `_search_config` table)
$engine->setConfig('last_rebuild_at', now()->toIso8601String());
$lastRebuild = $engine->getConfig('last_rebuild_at');
```

### Debugbar Integration

illumi-search integrates with [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) to display FTS5 queries directly in the debug toolbar.

```bash
composer require --dev barryvdh/laravel-debugbar
```

No configuration needed. Once installed, an **illumi-search** tab appears in the debugbar showing:

- **Engine info** — version, tokenizer, total indexed records
- **FTS5 queries** — `MATCH` statement, model class, result count, duration
- **BM25 scores** — top 3 ranking scores per query
- **Search mode** — advanced / basic / raw

---

Isolate search indexes per tenant. Each tenant gets its own SQLite file.

### Configuration

```php
// config/illumi-search.php
'tenancy' => [
    'enabled' => env('ILLUMI_SEARCH_TENANCY', false),
    'directory' => 'app/search/tenants',
],
```

### Setup

Register a resolver closure that returns a unique tenant ID:

```php
// In AppServiceProvider::boot()
use Moaines\IllumiSearch\TenantManager;

$this->app->resolving(TenantManager::class, function (TenantManager $manager) {
    $manager->setResolver(fn () => tenant()->id);  // your tenant ID
});
```

The SQLite files are stored in:
```
 storage/app/search/tenants/{tenant_id}/search-index.sqlite
```

When tenancy is disabled (default), the path is `storage/app/search/search-index.sqlite` as usual.

> **Note:** The `Engine` is a singleton within a single request. If you switch tenants mid-request (e.g., in a queue job processing multiple tenants), you must manually clear the engine instance. In a standard HTTP request lifecycle, this is not an issue since the engine is resolved once per request.

> **Note:** The FTS5 vocab tables (used by spellcheck) are updated automatically by FTS5 when data is inserted or modified — no manual sync needed.

---

## Authorization

Filter search results by user permissions using Laravel's Gate/Policy system.

### Enable

```php
$results = IllumiSearch::query('laravel')
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

$results = IllumiSearch::query('laravel')
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

Each `Result` has an `authorized` boolean (default `true`). When authorization is enabled, unauthorized results are **removed** from the collection.

---

## Security

### SQLite file protection

The FTS5 index is stored in `storage/app/search/search-index.sqlite` by default. Laravel's `storage/` directory is already protected from web access, but ensure:

- The file is **not** committed to version control (it's in `.gitignore` by default)
- File permissions restrict access to the web server user only (`chmod 600`)
- No route or controller exposes the file for download

### Data exposure via snippets

All searchable columns are eligible for context snippets by default. If a column contains sensitive data (PII, internal notes, secrets), disable snippets:

```php
protected array $searchable = [
    'email'         => ['snippet' => false],
    'internal_note' => ['snippet' => false],
];
```

### Authorization

Results can be filtered through Laravel Policies or Spatie/Shield permissions. See the [Authorization section](#authorization) for details.

---

## Artisan Commands

### `php artisan illumi-search:rebuild`

Drop and recreate all FTS5 tables, then repopulate from Eloquent models.

```
Options:
  --model=CLASS       Rebuild specific model(s) (repeatable: --model=Post --model=Comment)
  --force             Skip confirmation
  --batch-size=N      Index N records now, queue the rest (default: config)
  --vacuum            Run VACUUM after rebuilding
```

### `php artisan illumi-search:sync`

Incremental sync of changed records.

```
Options:
  --model=CLASS  Sync specific model(s) (repeatable: --model=Post --model=Comment)
  --since=DATE   Only records updated after date
```

### `php artisan illumi-search:check`

Detect schema drift between model declarations and index.

```
+---------------------+---------+----------------------+--------+
| Model               | Version | Columns              | Status |
+---------------------+---------+----------------------+--------+
| App\Models\Post     | 1       | title, body          | OK     |
| App\Models\Comment  | 2       | content, author_name | DRIFT  |
+---------------------+---------+----------------------+--------+
```

### `php artisan illumi-search:status`

Index statistics.

```
Search Database: storage/app/search/search-index.sqlite
Size: 12.4 MB
Total indexed records: 6,804

Model                    Records   Last Synced
App\Models\Post          1,234     2026-07-05 12:00:00
App\Models\Comment       5,678     2026-07-05 12:00:00
```

### `php artisan illumi-search:optimize`

Run VACUUM and FTS5 merge optimization to reclaim space and improve performance.

```
Search Database: storage/app/search/search-index.sqlite
Size before: 14.2 MB

Running VACUUM...
Running FTS5 merge optimization...

Size after:  12.4 MB
Space saved: 1.8 MB
Tables optimized: 2
```

### `php artisan illumi-search:search`

Search the index directly from the command line.

```bash
php artisan illumi-search:search "laravel"
php artisan illumi-search:search "laravel" --models=Post,Comment --limit=5 --json
php artisan illumi-search:search "laravel" --suggest     # with spellcheck
```

Options:

| Option | Description |
|--------|-------------|
| `--models=Post,Comment` | Comma-separated model classes |
| `--limit=10` | Max results |
| `--mode=advanced` | Search mode: `basic`, `advanced`, or `raw` |
| `--json` | Output as JSON (for scripting) |
| `--suggest` | Include spellcheck suggestions when no results |

### `php artisan illumi-search:doctor`

Diagnose the FTS5 environment — extensions, FTS5 support, database health, configuration, and per-table integrity checks.

The doctor command also runs FTS5's built-in `integrity-check` on each indexed table to detect corruption.

```
🔍 Illumi Search Environment Diagnostics

1. PHP Extensions
 ✓ ext-sqlite3
 ✓ ext-intl
 ✓ ext-mbstring
 ✓ ext-pdo_sqlite

2. SQLite FTS5 Support
 ✓ FTS5 is available (SQLite 3.52.0)

3. Search Database
 ✓ Path: storage/app/search/search-index.sqlite
 ✓ Size: 12.4 MB
 ✓ Readable / Writable

  Indexes:
  - App\Models\Post: 1,234 records

  Integrity:
  ✓ App\Models\Post

 4. Configuration
  illumi-search.indexing = queue
  illumi-search.mode = advanced
 ...

5. FTS5 Operators
 ✓ AND
 ✓ OR
 ✓ NOT
 ✗ NEAR

✅ All checks passed
```

### `php artisan illumi-search:discover-filament`

Analyze Filament panel Resources to discover `$searchable` columns for your models.

```bash
php artisan illumi-search:discover-filament
php artisan illumi-search:discover-filament --panel=admin
php artisan illumi-search:discover-filament --format=json
```

The command reads `getGloballySearchableAttributes()` from each Resource. If the Resource does not override this method, it falls back to `$recordTitleAttribute`. Discovery includes a default weight heuristic: the record title attribute gets weight 3, other columns get weight 1.

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
│  │  - toSearchDocument()                                 │  │
│  └──────────────────────┬────────────────────────────┘  │
│                         │                                │
│  ┌──────────────────────▼────────────────────────────┐  │
│  │              SqliteEngine                       │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  storage/app/search/search-index.sqlite            │  │  │
│  │  │  ┌───────────────────────────────────────┐  │  │  │
│  │  │  │  idx_posts (FTS5 virtual table)       │  │  │  │
│  │  │  │  idx_comments (FTS5 virtual table)    │  │  │  │
│   │  │  │  _search_meta (schema tracking)          │  │  │  │
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
Model saved → Job dispatched (or sync) → toSearchDocument()
  → TextProcessor::process() → SQLite FTS5 INSERT
```

### Search flow

```
User types query → escapeQuery() → normalizeQuery()
  → FTS5 MATCH → BM25 ranking → enrichWithSnippets()
    → Load original Eloquent models
    → Extract context with <mark> highlighting
    → Return Result[]
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

> **Fallback mode:** When `ext-intl` is not available on your system, a `FallbackTextProcessor` is used instead. It replaces `Transliterator` with Symfony's `UnicodeString::ascii()` which handles most Latin accents, Arabic tashkeel, and Cyrillic transliteration. CJK separation and HTML stripping remain identical. Run `php artisan illumi-search:doctor` to check which processor is active.

### Stopwords

Common words (le, la, les, the, and, of, etc.) can be filtered out during indexing to reduce index size and improve BM25 relevance.

Enable stopword filtering by configuring language codes:

```php
// config/illumi-search.php
'stopwords' => ['fr', 'en', 'ar'],
```

The package includes word lists for **33 languages**: Arabic, Bulgarian, Catalan, Chinese, Croatian, Czech, Danish, Dutch, English, Finnish, French, German, Greek, Hebrew, Hindi, Hungarian, Indonesian, Italian, Japanese, Korean, Malay, Norwegian, Persian, Polish, Portuguese, Romanian, Russian, Slovak, Slovenian, Spanish, Swedish, Turkish, Ukrainian.

Words are filtered per-indexed-model based on the model's locale configuration. When no languages are configured (`stopwords: []`), filtering is disabled.

> 💡 **Tip:** Filtering is applied after accent removal and lowercasing, so `"L'élève"` is correctly matched as `"leleve"` → stopword `"le"` is removed → `"eve"` remains in the index.

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
// config/illumi-search.php
'indexing' => 'queue',
```

### Sync

Update the index immediately in the same request. Best for small datasets or tests.

```php
'indexing' => 'sync',
```

### Manual

No auto-indexing. Use `php artisan illumi-search:rebuild` and `php artisan illumi-search:sync` explicitly.

```php
'indexing' => 'manual',
```

### Lazy Rebuild (batch + queue)

For large datasets, `illumi-search:rebuild` can index the first N records synchronously and dispatch queue jobs for the rest. This prevents timeouts on big tables while still providing immediate search results for the initial batch.

```env
ILLUMI_SEARCH_REBUILD_BATCH_SIZE=500
```

Or pass `--batch-size` to the command:

```bash
# Index first 500 records now, queue the rest
php artisan illumi-search:rebuild --batch-size=500

# Override config for a specific run
php artisan illumi-search:rebuild --batch-size=1000
```

Output example:

```
  ✓ App\Models\Post: 500 records indexed, 12300 dispatched to queue (total: 12800)
  ✓ App\Models\Comment: 500 records indexed, 8700 dispatched to queue (total: 9200)
```

The queued `IndexBatchJob` jobs process records in chunks of 100 each. Set `ILLUMI_SEARCH_REBUILD_BATCH_SIZE=0` to always index everything synchronously (default).

---

## Architecture & Commands

When a model using the `Searchable` trait is saved, deleted, or restored:

```
┌─────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│  Model::save()  │───▶│  Eloquent Event  │───▶│  Queue Job       │
│  Model::delete()│    │  (saved /        │    │  IndexModelJob   │
│  Model::restore()│   │   deleted /      │    │  ─ or ─          │
│                 │    │   restored)      │    │  syncToSearch()     │
└─────────────────┘    └──────────────────┘    └────────┬─────────┘
                                                         ▼
                                                ┌──────────────────┐
                                                │  SQLite FTS5     │
                                                │  Index           │
                                                │  (upsert/delete) │
                                                └──────────────────┘
```

| Indexing mode | Auto-indexing | Queue needed | Best for |
|---------------|--------------|-------------|----------|
| `queue` (default) | ✅ Yes, async | ✅ Yes | Production — keeps HTTP fast |
| `sync` | ✅ Yes, sync | ❌ No | Small datasets, dev, tests |
| `manual` | ❌ No | ❌ No | Full control via commands |

### Command Reference

| Command | Description | Example |
|---------|-------------|---------|
| `illumi-search:rebuild` | Full rebuild from scratch | `php artisan illumi-search:rebuild` |
| `illumi-search:rebuild --model=Post` | Rebuild a single model | `php artisan illumi-search:rebuild --model="App\Models\Post"` |
| `illumi-search:rebuild --batch-size=500` | Batch sync + queue for large tables | `php artisan illumi-search:rebuild --batch-size=500` |
| `illumi-search:rebuild --vacuum` | Rebuild + VACUUM | `php artisan illumi-search:rebuild --vacuum` |
| `illumi-search:sync` | Incremental sync | `php artisan illumi-search:sync` |
| `illumi-search:sync --since="2026-01-15"` | Sync since a specific date | `php artisan illumi-search:sync --since="2026-01-15"` |
| `illumi-search:sync --since="2026-01-15 14:30:00"` | Sync since date+time | `php artisan illumi-search:sync --since="2026-01-15 14:30:00"` |
| `illumi-search:sync --model=Comment` | Sync a single model | `php artisan illumi-search:sync --model="App\Models\Comment"` |
| `illumi-search:doctor` | Full health check | `php artisan illumi-search:doctor` |
| `illumi-search:optimize` | Merge segments + VACUUM | `php artisan illumi-search:optimize` |
| `illumi-search:search` | Search from CLI | `php artisan illumi-search:search "laravel"` |
| `illumi-search:search --models=Post` | Search specific models | `php artisan illumi-search:search "php" --models=Post,Comment` |
| `illumi-search:search --json` | Search as JSON | `php artisan illumi-search:search "laravel" --json` |

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

> ¹ Available operators are auto-detected at runtime. Run `php artisan illumi-search:doctor` to see which are available. Restrict with `config('illumi-search.operators.enabled')` (see [Configuration](#configuration)). NEAR is unsupported in some SQLite builds — when unsupported, it's automatically converted to AND.

---

## Testing

```bash
phpunit                                     # Run all tests
phpunit --testdox                           # Named tests
phpunit tests/Unit/TextProcessorTest.php    # Single test file
phpstan analyse                             # Static analysis
pint                                        # Code style
```

### Test coverage

**216 tests** covering unit and feature suites, including:

- `TextProcessor` — accent folding, CJK character separation, Korean, HTML stripping, invalid UTF-8 graceful fallback, Porter stemming for EN/FR
- `Spellcheck` — Levenshtein-based suggestions with configurable distance and frequency ordering, exact-match exclusion, vocab limits
- `Authorization` — policy-based result filtering, respect for `view`/`viewAny` gates, graceful fallback when no user is authenticated
- `Multi-tenant isolation` — tenant-aware database paths, unique index per tenant, no cross-tenant data leakage
- `Bad-query resilience` — unclosed quotes, hanging operators (`NOT`/`AND`/`OR` at start), invalid NEAR syntax, normal search still works after any malformed query
- `Graceful degradation` — `isFts5Available()`, `getEngineVersion()` shows `(FTS5 unavailable)` when missing, `DoctorCommand` with actionable resolution steps
- `Diagnostics & config` — `fullIntegrityCheck()` across all FTS5 tables, persistent config read/write via `_search_config`, unsafe PRAGMA rejection

---

## Package Structure

```
illumi-search/
├── config/illumi-search.php
├── src/
│   ├── Contracts/          # Engine, TextProcessor interfaces
│   ├── Engines/            # SqliteEngine
│   ├── Text/               # UnicodeTextProcessor, StemmingTextProcessor
│   ├── Console/Commands/   # rebuild, sync, search, check, status, doctor, discover-filament, optimize
│   ├── Http/Controllers/   # SearchApiController
│   ├── Http/Requests/      # SearchApiRequest
│   ├── Jobs/               # IndexModelJob, DeleteIndexJob, IndexBatchJob
│   ├── Facades/IllumiSearch.php
│   ├── QueryBuilder.php
│   ├── Result.php
│   ├── IndexManager.php
│   ├── Searchable.php      # Eloquent trait
│   └── Exceptions/
└── tests/
```

---

## Limitations

- **Cloud object storage not supported.** The FTS5 index is a SQLite database file and must reside on a local filesystem. S3, GCS, or any HTTP-based storage driver cannot be used — `database_path` always resolves to a local path. See [Requirements](#requirements).
- **Ephemeral environments.** On serverless platforms (Laravel Vapor, Kubernetes without persistent volumes), the index file is lost on redeploy. Use an absolute `ILLUMI_SEARCH_DATABASE_PATH` pointing to a mounted persistent volume, or re-run `php artisan illumi-search:rebuild` after each deploy.
- **Multi-server setups.** SQLite handles concurrent reads well but does not support concurrent writes from multiple processes over NFS. Use a single-writer strategy or consider an external search service for horizontal scaling.

---

## Changelog

### Unreleased

### v1.11.0

- **REST API.** New `/api/search` endpoint with rate limiting. Supports `?q=laravel&models=Post,Comment&limit=10&suggest=1`. Enable with `ILLUMI_SEARCH_API_ENABLED=true`. Compatible with comma-separated `&models=Post,Comment` and array `&models[]=Post&models[]=Comment` syntax.
- **CLI search.** New `php artisan illumi-search:search` command. Search directly from the terminal with `--models`, `--limit`, `--mode`, `--json`, and `--suggest` options.
- **Code cleanup.** Removed 2 dead imports, extracted ProgressBar trait (eliminating 29 lines of duplication), deduplicated saved/restored event closures, split `DoctorCommand::handle()`, `IndexManager::rebuild()`, and `SqliteEngine::escapeQuery()` into focused private methods.

### v1.10.0

- **Queue connection support.** Implemented `illumi-search.queue_connection` (`ILLUMI_SEARCH_QUEUE_CONNECTION`). Set a specific queue for FTS indexing jobs (e.g. `ILLUMI_SEARCH_QUEUE_CONNECTION=database`). When `null` (default), uses the application's default queue connection.
- **Multi-language stemming.** New text processor `stemming` (`ILLUMI_SEARCH_PROCESSOR=stemming`) powered by [wamania/php-stemmer](https://github.com/wamania/php-stemmer). Stems words in 13 languages (en, fr, es, pt, de, it, ru, nl, sv, no, da, ro, ca, fi). Unknown languages fall back to unicode processing silently. Default: `unicode`.
- **Tokenizer options documented.** Built-in tokenizers: `unicode61` (default), `ascii`, `porter`, `trigram`. Porter can wrap any tokenizer (`porter unicode61`, `porter ascii`). Trigram enables substring matching (LIKE-style `%search%`).
- **Column-size option.** New `illumi-search.fts5.columnsize` config (`1` or `0`). Set to `0` to omit column size storage — saves ~10% disk space with slightly less accurate BM25 ranking. Default: `1`.
- **New diagnostics API.** `getEngineVersion()`, `getPragma()`, `fullIntegrityCheck()`, `getConfig()`, and `setConfig()` methods on `Engine` for index introspection and health checks.
- **Safe PRAGMA whitelist.** Only read-only PRAGMAs are allowed via `getPragma()` (journal_mode, cache_size, busy_timeout, synchronous, etc.).
- **WAL mode + performance PRAGMAs.** Enabled by default: WAL journal mode (concurrent reads/writes), `synchronous=NORMAL` (safe with WAL), 64 MB cache, in-memory temp storage, and 5s busy timeout. Optional mmap I/O (`ILLUMI_SEARCH_MMAP_SIZE`) for faster reads on large indexes — **disabled by default** (set `ILLUMI_SEARCH_MMAP_SIZE=1073741824` for 1 GB). ⚠️ mmap is incompatible with network filesystems (NFS, SMB) and some Docker/OCI mounts. Test thoroughly in production.

### v1.9.0

- **FTS5 detail option.** New `illumi-search.fts5.detail` config (`full`, `column`, or `none`). `column` shrinks the FTS index ~30% (total DB reduction is smaller — document content is unchanged).
- **Merge tuning.** New `illumi-search.fts5.automerge`, `illumi-search.fts5.crisismerge`, and `illumi-search.fts5.pgsz` config options for fine-grained control over index segment merging and page size.
- **`integrityCheck()`.** New method on `Engine` interface. Performs FTS5 integrity-check on each indexed table.
- **`illumi-search:doctor` integrity checks.** The doctor command now shows per-table integrity status (✅ or ❌).
- **Optimized `enrichWithSnippets()`.** Snippet loading now uses `SELECT` only for columns declared in `$searchable`, eager-loads relations for dot-notation columns, and detects virtual attributes via `Schema::hasColumn()`.

### v1.8.0

- **Snippet fix for dot-notation columns.** When a term matches in a relation (e.g. `comments.body`), the snippet now extracts text from the correct column via `resolveSearchValue()`. Chooses the column that actually contains the search term.
- **Orphaned meta cleanup.** `getIndexStats()` no longer crashes on orphaned meta entries. Cleans them up automatically.
- **`resolveSearchValue()` made public.** Allows the engine to resolve dot-notation values for snippets.

### v1.7.0

- **Real-time progress bars.** `illumi-search:rebuild` and `illumi-search:sync` now display a per-model progress bar with current/max count and elapsed time.
- **Eager-loaded relations.** Dot-notation columns (`writer.name`, `comments.body`) are now eager-loaded during chunks, eliminating N+1 queries on rebuild and sync.

### v1.6.0

- **Column name sanitization.** Dots (`.`), arrows (`->`), and dashes (`-`) in `$searchable` column names are now automatically converted to underscores (`_`) for FTS5. `'comments.body'` becomes `comments_body` in the index — FTS5 no longer rejects them.
- **`searchColumnName()` helper** on the Searchable trait.
- **`sanitizeDocumentKeys()`** in the engine ensures all document keys are valid SQL identifiers.

### v1.5.0

- **Auto-cleanup orphaned tables.** `illumi-search:rebuild` now removes index tables for models that no longer use the `Searchable` trait.
- **New methods on `Engine` interface:** `tableName()`, `listIndexTables()`, `dropIndexTable()`.

### v1.4.2

- **`illumi-search:discover-filament` shows PHP code block.** When columns are missing, the command now displays a copy-paste ready `$searchable` snippet for each model.

### v1.4.1

- **`illumi-search:discover-filament` CLI fallback.** Falls back to the first available panel when `getCurrentPanel()` returns null (running in CLI).

### v1.4.0

- **`illumi-search:discover-filament` command.** Analyzes Filament panel Resources and discovers `$searchable` columns with heuristic weights. Falls back to `$recordTitleAttribute` when `getGloballySearchableAttributes()` is null. Handles dot notation, virtual attributes, and missing panels gracefully. Outputs table or JSON.

### v1.3.0

- **Dot notation in `searchable`.** Columns like `'writer.name'` and `'comments.body'` auto-resolve related model attributes. Supports all Eloquent relationships (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `morphOne`, `morphMany`, `morphTo`) and custom accessors. Null-safe, collection-safe, limited by `max_related_values` (default: 100).
- **`validateSearchable()`.** Emits warnings during `illumi-search:rebuild` for dot-notation columns referencing non-existent relations.

### v1.2.0

- **Absolute database path.** `ILLUMI_SEARCH_DATABASE_PATH` starting with `/` is used as-is (for persistent volumes on Vapor/K8s). Relative paths still resolve via `storage_path()`.
- **`--vacuum` flag.** `php artisan illumi-search:rebuild --vacuum` runs VACUUM after rebuilding. Without the flag, VACUUM is skipped for faster rebuilds.
- **`illumi-search:doctor` improvements.** Displays path type (absolute/relative) and free disk space.
- **Optional snippets.** `search()` accepts `$withSnippets` parameter. Set to `false` to skip Eloquent model loading when snippets are not needed.
- **Model discovery cache.** `discoverModels()` caches results in memory within the same request — no redundant filesystem scans.
- **Events.** `RebuildComplete` and `ModelIndexed` events are dispatched during rebuild.
- **Batch jobs use `where(>)` instead of `offset()`.** No more shift on record deletion between queue jobs.
- **`sync()` respects custom timestamps.** Uses `$model->getUpdatedAtColumn()` instead of hardcoded `updated_at`.
- **Operator reset.** `SqliteEngine::resetOperators()` allows resetting operator state between tests (avoids static state leakage).
- **`Spellcheck` per-instance.** Changed from `singleton` to `bind` — `maxDistance`/`maxSuggestions` no longer leak between callers.
- **Removed unused `--mode` option** from `illumi-search:rebuild` command.
- **`escapeQuery()` with cache.** Repeated calls with the same query + mode reuse the cached result.
- **Deduplicated `extractTerms()`** — now shared via `HasQueryTerms` trait.
- **Fixed `normalize()` dead branch.** `Normalizer::normalize()` failure now returns the original text instead of an empty string.

---

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo_64x64_dark.png">
    <img src="art/logo_64x64_transparent.png" alt="Illumi Search" width="64">
  </picture>
</p>

## License

MIT
