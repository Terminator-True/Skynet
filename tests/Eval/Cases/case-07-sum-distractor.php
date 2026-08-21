<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'My groceries came to 30 and 20 — what did I spend in total?',
    expectedToolName: 'calculate_sum',
    semantics: fn (array $call): bool => $call['result']['sum'] == 50.0,
    note: 'Distractor framing (shopping story); numbers must be extracted correctly.',
);
