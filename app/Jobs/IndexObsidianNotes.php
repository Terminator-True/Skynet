<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled sweep (REQ scheduled): one queued job per 15-min tick that
 * resolves the single owner (first-user-wins precedent) and re-vectorizes the
 * local Obsidian vault. Requires `php artisan queue:work` to drain the database
 * queue, alongside `php artisan schedule:work`.
 */
class IndexObsidianNotes implements ShouldQueue
{
    use Queueable;

    public function handle(NotesIndexerService $indexer): void
    {
        $user = User::query()->first();

        if ($user === null) {
            return;
        }

        $indexer->index($user);
    }
}
