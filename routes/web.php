<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('welcome');

// Fallback for hosts where the public/storage symlink is not served by the
// web server. Only profile avatars are exposed because they are public UI
// assets; private vehicle documents never use this route.
Route::get('/avatar/{path}', function (string $path) {
    abort_unless((bool) preg_match('#^avatars/[^/]+/[^/]+$#', $path), 404);

    $disk = Storage::disk('public');
    abort_unless($disk->exists($path), 404);

    return $disk->response($path, basename($path), [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', 'avatars/[^/]+/[^/]+')->name('public.avatar');
