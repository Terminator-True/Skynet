<?php

return [

    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),

    // Config-only model swap (spec requirement).
    'model' => env('OLLAMA_MODEL', 'qwen2.5:14b'),

    'fallback_model' => env('OLLAMA_FALLBACK_MODEL', 'llama3.1:8b'),

    // Context window cap kept low (4-8k) to leave VRAM headroom on 12GB.
    'num_ctx' => (int) env('OLLAMA_NUM_CTX', 4096),

    // Email bodies are trimmed to this many chars before the focused
    // extraction prompt so it always fits inside num_ctx.
    'extraction_char_cap' => (int) env('OLLAMA_EXTRACTION_CHAR_CAP', 6000),

    // Tool results fed back into the model context are truncated to this many
    // chars so a single large result (e.g. a long email body) can never
    // saturate num_ctx and stall the loop.
    'tool_result_char_cap' => (int) env('OLLAMA_TOOL_RESULT_CHAR_CAP', 6000),

    // Hard bound for the tool-calling loop; exhaustion returns a structured error.
    'max_tool_iterations' => (int) env('OLLAMA_MAX_TOOL_ITERATIONS', 4),

    // Eval opt-in: plain `pest` runs never touch hardware.
    'eval_enabled' => (bool) env('OLLAMA_EVAL', false),

    // Embedding model for long-term preference memory (Fase 6, local-only).
    'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),

    // Recall bounds for prompt injection: top-k entries, each char-capped.
    'memory_recall_top_k' => 3,
    'memory_recall_char_cap' => 200,

];
