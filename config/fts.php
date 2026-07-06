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

    'fts5' => [
        /*
        | Tokenizer: 'unicode61', 'porter', or 'unicode61 remove_diacritics 2'.
        */
        'tokenizer' => 'unicode61',

        /*
        | Prefix lengths for search-as-you-type (advanced mode only).
        | Generates additional index entries for prefix queries.
        */
        'prefix_lengths' => [2, 3, 4],
    ],
];
