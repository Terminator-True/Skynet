<?php

namespace App\Console\Commands;

use App\Services\Notes\NotesIndexerService;
use Illuminate\Console\Command;

/**
 * Manual trigger for the notes indexer (REQ scheduled). Runs the same service
 * the IndexObsidianNotes job invokes, against the configured vault path.
 */
class NotesIndex extends Command
{
    protected $signature = 'notes:index';

    protected $description = 'Index the local Obsidian vault chunks into notes_index';

    public function handle(NotesIndexerService $indexer): int
    {
        $vault = config('notes.vault_path');

        if (! is_string($vault) || ! is_dir($vault)) {
            $this->warn("Vault path not found: {$vault} — nothing indexed.");

            return self::SUCCESS;
        }

        $embedded = $indexer->index();

        $this->info("Indexed {$embedded} chunks.");

        return self::SUCCESS;
    }
}
