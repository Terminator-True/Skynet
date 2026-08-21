<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Add 12.5 and 7.5 for me.',
    expectedToolName: 'calculate_sum',
    semantics: fn (array $call): bool => $call['result']['sum'] == 20.0,
    note: 'Decimal arguments must stay numeric.',
);
