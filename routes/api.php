<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

// Stateless sync JSON contract (Fase 0): transport layer only — swappable to
// SSE later without touching the orchestrator core.
Route::post('/chat', [ChatController::class, 'store'])
    ->name('chat.store');
