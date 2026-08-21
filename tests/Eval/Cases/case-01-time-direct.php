<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'What time is it right now?',
    expectedToolName: 'get_current_time',
    note: 'Direct zero-arg tool call.',
);
