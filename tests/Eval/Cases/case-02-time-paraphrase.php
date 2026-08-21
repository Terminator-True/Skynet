<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Can you tell me today\'s date?',
    expectedToolName: 'get_current_time',
    note: 'Paraphrase: "date" instead of "time".',
);
