<?php

return [

    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),

    // Config-only model swap (spec requirement).
    'model' => env('OLLAMA_MODEL', 'qwen2.5:14b'),

    'fallback_model' => env('OLLAMA_FALLBACK_MODEL', 'llama3.1:8b'),

    // Context window cap kept low (4-8k) to leave VRAM headroom on 12GB.
    'num_ctx' => (int) env('OLLAMA_NUM_CTX', 4096),

    // Hard bound for the tool-calling loop; exhaustion returns a structured error.
    'max_tool_iterations' => (int) env('OLLAMA_MAX_TOOL_ITERATIONS', 4),

    // Eval opt-in: plain `pest` runs never touch hardware.
    'eval_enabled' => (bool) env('OLLAMA_EVAL', false),

];
