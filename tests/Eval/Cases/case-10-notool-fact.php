<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Tell me a one-sentence fun fact about the Moon.',
    expectedToolName: null,
    note: 'No-tool case: general knowledge, must not over-call tools. Counts toward the gate.',
);
