<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Say hello.',
    expectedToolName: null,
    note: 'No-tool case: must answer directly, zero tool calls. Counts toward the gate.',
);
