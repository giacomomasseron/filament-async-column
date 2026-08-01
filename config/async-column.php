<?php

return [
    // Cache store used by ->cacheFor(). Null = the application default store.
    'cache_store' => env('ASYNC_COLUMN_CACHE_STORE'),

    // Prefix for every cache key written by this package.
    'cache_prefix' => 'async-column',

    // Maximum number of cell tokens BatchResolver will process in a single
    // request. Extra tokens beyond this cap are silently truncated (never an
    // error), so a single request cannot force arbitrarily many resolver
    // invocations. A value <= 0 — including a malformed env value, which
    // (int) silently casts to 0 — is treated as invalid and falls back to
    // the default of 200 rather than disabling the cap.
    'max_batch_size' => (int) env('ASYNC_COLUMN_MAX_BATCH_SIZE', 200),
];
