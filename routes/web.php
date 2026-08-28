<?php

use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('/chat', 'Chat')->name('chat');

Route::inertia('/voice', 'VoiceChat')->name('voice');

// Serve the gitignored local voice models (/models/*) from public/models.
// Whisper (STT), piper (TTS) and the onnxruntime glue are fetched from this
// path at runtime; serving it here works regardless of how the Vite dev server
// handles public/ static files.
Route::get('/models/{path}', function (string $path) {
    $root = realpath(public_path('models'));

    if ($root === false) {
        abort(404);
    }

    $file = realpath($root.'/'.$path);

    if (
        $file === false
        || ! is_file($file)
        || ! Str::startsWith($file, $root.DIRECTORY_SEPARATOR)
    ) {
        abort(404);
    }

    return response()->file($file);
})->where('path', '.*')->name('models');

Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])
    ->name('google.callback');

Route::get('/connect', [GoogleOAuthController::class, 'status'])->name('connect');
