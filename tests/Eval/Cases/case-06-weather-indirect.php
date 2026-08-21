<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Do I need an umbrella in Buenos Aires today?',
    expectedToolName: 'get_weather_mock',
    semantics: fn (array $call): bool => str_contains(strtolower((string) $call['arguments']['city']), 'buenos aires'),
    note: 'Indirect weather intent ("umbrella") with multi-word city.',
);
