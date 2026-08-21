<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Before I forget — what\'s the current hour?',
    expectedToolName: 'get_current_time',
    note: 'Distractor preamble before the real request.',
);
