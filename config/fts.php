<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Path
    |--------------------------------------------------------------------------
    |
    | Path to the SQLite index file, relative to storage_path().
    |
    */
    'database_path' => env('FTS_DATABASE_PATH', 'app/fts/fts-index.sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Search Mode
    |--------------------------------------------------------------------------
    |
    | Default search mode: 'basic' or 'advanced'.
    |
    | Basic: simple keyword matching with trailing wildcards.
    | Advanced: column-specific, phrase, NEAR, prefix, and boolean queries.
    |
    */
    'mode' => env('FTS_MODE', 'advanced'),

    /*
    |--------------------------------------------------------------------------
    | Indexing Strategy
    |--------------------------------------------------------------------------
    |
    | How models are synced to the FTS5 index:
    | - 'queue' (default): dispatch jobs on model save/delete
    | - 'sync': update index immediately in the same request
    | - 'manual': no auto-indexing, use artisan commands only
    |
    */
    'indexing' => env('FTS_INDEXING', 'queue'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | Queue connection for index jobs. Set to null to use the default queue.
    |
    */
    'queue_connection' => env('FTS_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Rebuild Batch Size
    |--------------------------------------------------------------------------
    |
    | When running fts:rebuild, if a model has more records than this value,
    | the first batch is indexed synchronously and the remaining records are
    | dispatched as queue jobs to avoid timeouts on large datasets.
    |
    | Set to 0 to always index everything synchronously (default behavior).
    |
    */
    'rebuild_batch_size' => env('FTS_REBUILD_BATCH_SIZE', 0),

    /*
    |--------------------------------------------------------------------------
    | Max Results
    |--------------------------------------------------------------------------
    |
    | Maximum number of search results returned per query.
    |
    */
    'max_results' => 50,

    /*
    |--------------------------------------------------------------------------
    | Model Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan for models using the Searchable trait.
    |
    */
    'model_paths' => [
        app_path('Models'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FTS5 Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Fine-tune the FTS5 virtual table creation.
    |
     */
    'fts5' => [
        /*
        | Tokenizer.
        | Built-in options:
        |   unicode61              = Unicode-aware (default). Supports CJK, accents.
        |   ascii                  = ASCII-only. Faster on English-only data.
        |   porter                 | Porter stemmer. 'porter unicode61' for stemming.
        |   trigram                | Substring matching (3-char tokens). LIKE %search%.
        | Examples:
        |   'unicode61 remove_diacritics 2'  = force diacritics removal
        |   'porter ascii'                   = Porter stemmer on ASCII
        |   'trigram'                        = LIKE-style matching
        */
        'tokenizer' => 'unicode61',

        /*
        | Text processor: 'unicode' (default) or 'stemming'.
        |   unicode  = NFC normalization + diacritics removal + CJK separation (default)
        |   stemming = same as unicode + multi-language word stemming via wamania/php-stemmer.
        |              Supports: en, fr, es, pt, de, it, ru, nl, sv, no, da, ro, ca, fi.
        |              Unknown languages fall back to unicode processing silently.
        | Default: 'unicode'
        */
        'processor' => env('FTS_PROCESSOR', 'unicode'),

        /*
        | Prefix lengths for search-as-you-type (advanced mode only).
        | Generates additional index entries for prefix queries.
        */
        'prefix_lengths' => [2, 3, 4],

        /*
        | Detail: 'full', 'column', or 'none'.
        | - full: stores term + position + column (supports NEAR/phrase)
        | - column: stores term + column only. FTS index shrinks ~30%.
        |   Total DB reduction is smaller since document content is unchanged.
        | - none: stores term only. FTS index shrinks ~50%.
        |   No column-based ranking.
        */
        'detail' => 'full',

        /*
        | Column-size storage.
        | 1 = store column sizes (default, needed for BM25 ranking accuracy)
        | 0 = omit column sizes (saves ~10% disk space, BM25 slightly less accurate)
        | Default: 1
        */
        'columnsize' => env('FTS_COLUMNSIZE', 1),

        /*
        | Automerge: segment count threshold for automatic merging.
        | Higher values reduce CPU during inserts, lower values improve query speed.
        | Default: 4
        */
        'automerge' => 4,

        /*
        | Crisismerge: emergency merge threshold.
        | When segments exceed this count, FTS5 forces a merge immediately.
        | Default: 16 (4x automerge)
        */
        'crisismerge' => 16,

        /*
        | Pgsz: maximum bytes per index page.
        | Larger pages = faster bulk insert, slower queries. Smaller = reverse.
        | Default: 1000
        */
        'pgsz' => 1000,

        /*
        | WAL mode: enables concurrent reads and writes.
        | Recommended for production. Disable only on NFS filesystems.
        | Default: true
        */
        'wal' => env('FTS_WAL', true),

        /*
        | Cache size in kilobytes (negative = kilobyte count).
        | -64000 ≈ 64 MB dedicated RAM for the FTS index cache.
        | Default: -64000 (64 MB)
        */
        'cache_size_kb' => env('FTS_CACHE_SIZE_KB', -64000),

        /*
        | Synchronous mode: 'NORMAL' (fast, safe with WAL) or 'FULL' (slowest, safest).
        | Default: 'NORMAL'
        */
        'synchronous' => env('FTS_SYNCHRONOUS', 'NORMAL'),

        /*
        | Temp storage: 'MEMORY' (faster) or 'FILE' (safer for low-RAM environments).
        | Default: 'MEMORY'
        */
        'temp_store' => env('FTS_TEMP_STORE', 'MEMORY'),

        /*
        | Busy timeout in milliseconds.
        | Time to wait before returning SQLITE_BUSY when a table is locked.
        | Essential for concurrent access with WAL mode — without it, a second
        | writer gets an immediate error.
        |   - 0         = no timeout (error immediately on lock)
        |   - 1000-5000 = recommended range for web apps
        |   - 10000+    = for long-running batch operations
        | Default: 5000 (5 seconds)
        */
        'busy_timeout' => env('FTS_BUSY_TIMEOUT', 5000),

        /*
        | Mmapsize in bytes.
        | Memory-mapped I/O for faster read queries (FTS5 search).
        | Only useful for large indexes (>100 MB). Set to 0 to disable.
        |   - 0          = disabled (default, same as SQLite default)
        |   - 67108864   = 64 MB
        |   - 268435456  = 256 MB
        |   - 1073741824 = 1 GB
        |
        | Limitations: mmap does not work on all filesystems. It is incompatible
        | with network filesystems (NFS, SMB) and certain Docker/OCI mounts.
        | Leave at 0 (disabled) on those systems to avoid crashes or corruption.
        | Test thoroughly before enabling in production.
        | Default: 0 (disabled)
        */
        'mmap_size' => env('FTS_MMAP_SIZE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | When enabled, search results are filtered through the model's Eloquent
    | Policy 'view' ability. Only results the current user is authorized to
    | see are returned.
    |
    | Requires the FtsQueryBuilder to have a user set (or Auth::user() is used).
    |
    */
    'authorization' => [
        'enabled' => env('FTS_AUTHORIZATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenant
    |--------------------------------------------------------------------------
    |
    | When enabled, each tenant gets its own isolated SQLite file.
    | The resolver should return a unique tenant identifier (string|int).
    |
    */
    'tenancy' => [
        'enabled' => env('FTS_TENANCY', false),
        'directory' => env('FTS_TENANCY_DIRECTORY', 'app/fts/tenants'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spellcheck
    |--------------------------------------------------------------------------
    |
    | Maximum terms to load from the FTS5 vocab table for spelling suggestions.
    | Higher values give better suggestions but may be slower on large indexes.
    |
    */
    'spellcheck' => [
        'vocab_limit' => env('FTS_SPELLCHECK_VOCAB_LIMIT', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | FTS5 Operators
    |--------------------------------------------------------------------------
    |
    | Restrict which FTS5 operators are allowed in advanced mode queries.
    | Unsupported operators are automatically detected and excluded.
    | Set to null to allow all operators supported by the SQLite build.
    | Example: ['AND', 'OR', 'NOT'] to disable NEAR.
    |
    */
    'operators' => [
        'enabled' => env('FTS_OPERATORS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Related Values
    |--------------------------------------------------------------------------
    |
    | Maximum number of related model values to collect for dot-notation columns
    | in ftsSearchable (e.g. 'comments.body'). Prevents oversized documents
    | when a model has thousands of related records.
    |
    */
    'max_related_values' => 100,

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | REST API endpoint for search queries.
    | Enabled by default in local environment.
    |
    */
    'api' => [
        'enabled'    => env('FTS_API_ENABLED', false),
        'middleware' => ['api'],
        'prefix'     => 'api/search',
        'rate_limit' => 30,
    ],
];
