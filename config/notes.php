<?php

return [

    // Local Obsidian vault root (roadmap §8 local-only; gitignored for privacy).
    'vault_path' => env('NOTES_VAULT_PATH', base_path('main_obsidian')),

    // Chunk budget for a note with no ## headers (fits embed + num_ctx, D3).
    'chunk_chars' => (int) env('NOTES_CHUNK_CHARS', 1500),

    // Recall bounds for the read-only buscar_notas tool (Slice 2).
    'top_k' => (int) env('NOTES_TOP_K', 3),
    'char_cap' => (int) env('NOTES_CHAR_CAP', 500),

];
