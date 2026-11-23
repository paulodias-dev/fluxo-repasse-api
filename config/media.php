<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media storage
    |--------------------------------------------------------------------------
    |
    | Media is private by default. The primary disk is selected through the
    | environment so local development and tests can continue using local
    | storage while production uses S3.
    |
    */
    'disk' => env('MEDIA_DISK', 'local'),

    // Used during migration and as a read fallback for files not migrated yet.
    'fallback_disk' => env('MEDIA_FALLBACK_DISK', 'local'),
    'fallback_disks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MEDIA_FALLBACK_DISKS', 'local,public'))
    ))),

    // Optional best-effort mirror while moving existing files to S3.
    'mirror_disk' => env('MEDIA_MIRROR_DISK'),
    'mirror_fail_open' => env('MEDIA_MIRROR_FAIL_OPEN', true),

    'url_mode' => env('MEDIA_URL_MODE', 'temporary'),
    'temporary_url_minutes' => (int) env('MEDIA_TEMPORARY_URL_MINUTES', 60),
    'temporary_url_cache_minutes' => (int) env('MEDIA_TEMPORARY_URL_CACHE_MINUTES', 55),

    // Only prefixes explicitly listed here may be served by the public media route.
    'public_prefixes' => ['avatars'],
];
