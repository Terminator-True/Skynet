<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'What\'s the weather like in Rosario?',
    expectedToolName: 'get_weather_mock',
    semantics: fn (array $call): bool => str_contains(strtolower((string) $call['arguments']['city']), 'rosario'),
    note: 'Direct single-string-arg call; city must match the prompt.',
);
