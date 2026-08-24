<?php

use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('/chat', 'Chat')->name('chat');

Route::inertia('/voice', 'VoiceChat')->name('voice');

Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])
    ->name('google.callback');

Route::get('/connect', [GoogleOAuthController::class, 'status'])->name('connect');
