<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search Driver
    |--------------------------------------------------------------------------
    |
    | 'sqlite' — SQLite FTS5 (default). Requires ext-sqlite3 with FTS5.
    | 'mysql' — MySQL 8.0+ with FULLTEXT index. Requires mysql connection.
    |
    */
    'driver' => env('ILLUMI_SEARCH_DRIVER', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    |
    | Shared text processing settings used by all engines before indexing.
    |
    */
    'processing' => [
        'mode' => env('ILLUMI_SEARCH_MODE', 'advanced'),

        'processor' => env('ILLUMI_SEARCH_PROCESSOR', 'unicode'),

        'stopwords' => ['en'],

        'max_search_text_length' => env('ILLUMI_SEARCH_MAX_TEXT_LENGTH', 65535),

        'max_weight' => env('ILLUMI_SEARCH_MAX_WEIGHT', 3),

        'max_results' => 50,

        'max_related_values' => 100,

        'table_prefix' => env('ILLUMI_SEARCH_TABLE_PREFIX', 'illumi_search_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | Batched/queued indexing behavior.
    |
    */
    'indexing' => [
        'mode' => env('ILLUMI_SEARCH_INDEXING', 'queue'),

        'queue' => env('ILLUMI_SEARCH_QUEUE_CONNECTION'),

        'rebuild_batch_size' => env('ILLUMI_SEARCH_REBUILD_BATCH_SIZE', 0),

        'model_paths' => [
            app_path('Models'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Model-level authorization via Laravel policies.
    |
    */
    'authorization' => [
        'enabled' => env('ILLUMI_SEARCH_AUTHORIZATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Isolate search indexes per tenant (SQLite only — separate database files).
    |
    */
    'tenancy' => [
        'enabled' => env('ILLUMI_SEARCH_TENANCY', false),
        'directory' => env('ILLUMI_SEARCH_TENANCY_DIRECTORY', 'app/search/tenants'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spellcheck
    |--------------------------------------------------------------------------
    |
    | "Did you mean?" vocabulary settings.
    |
    */
    'spellcheck' => [
        'vocab_limit' => env('ILLUMI_SEARCH_SPELLCHECK_VOCAB_LIMIT', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operators
    |--------------------------------------------------------------------------
    |
    | Enable/disable search operators (AND, OR, NOT, NEAR).
    | Affects SQLite FTS5 and MySQL BOOLEAN MODE.
    |
    */
    'operators' => [
        'enabled' => env('ILLUMI_SEARCH_OPERATORS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API
    |--------------------------------------------------------------------------
    |
    | Built-in search API endpoint.
    |
    */
    'api' => [
        'enabled' => env('ILLUMI_SEARCH_API_ENABLED', false),
        'middleware' => ['api'],
        'prefix' => 'api/search',
        'rate_limit' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Engine-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Each search engine driver can have its own settings.
    |
    */
    'engines' => [

        'sqlite' => [
            'database_path' => env('ILLUMI_SEARCH_DATABASE_PATH', 'app/search/search-index.sqlite'),

            'fts5' => [
                'tokenizer' => 'unicode61',

                'prefix_lengths' => [2, 3, 4],

                'detail' => 'full',

                'columnsize' => env('ILLUMI_SEARCH_COLUMNSIZE', 1),

                'automerge' => 4,

                'crisismerge' => 16,

                'pgsz' => 1000,
            ],

            'runtime' => [
                'wal' => env('ILLUMI_SEARCH_WAL', true),

                'cache_size_kb' => env('ILLUMI_SEARCH_CACHE_SIZE_KB', -64000),

                'synchronous' => env('ILLUMI_SEARCH_SYNCHRONOUS', 'NORMAL'),

                'temp_store' => env('ILLUMI_SEARCH_TEMP_STORE', 'MEMORY'),

                'busy_timeout' => env('ILLUMI_SEARCH_BUSY_TIMEOUT', 15000),

                'mmap_size' => env('ILLUMI_SEARCH_MMAP_SIZE', 0),
            ],
        ],

        'mysql' => [
            'connection' => [
                'host' => env('ILLUMI_SEARCH_MYSQL_HOST', '127.0.0.1'),
                'port' => env('ILLUMI_SEARCH_MYSQL_PORT', '3306'),
                'database' => env('ILLUMI_SEARCH_MYSQL_DATABASE', 'illumi_search'),
                'username' => env('ILLUMI_SEARCH_MYSQL_USERNAME', 'root'),
                'password' => env('ILLUMI_SEARCH_MYSQL_PASSWORD', ''),
            ],
        ],
    ],

];
