<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'How much is 47 + 58?',
    expectedToolName: 'calculate_sum',
    semantics: fn (array $call): bool => $call['result']['sum'] == 105.0,
    note: 'Direct addition; result must be arithmetically right.',
);
