<?php

use Tests\Eval\EvalCase;

$today = now(config('app.assistant_timezone'))->format('Y-m-d');

return new EvalCase(
    prompt: '¿Qué tengo hoy?',
    expectedToolName: 'listar_eventos_calendario',
    semantics: function (array $call) use ($today): bool {
        // desde/hasta must span today's local day (spec "Model can compute
        // today"): the injected system-prompt date is what makes this solvable.
        $desde = substr((string) ($call['arguments']['desde'] ?? ''), 0, 10);
        $hasta = substr((string) ($call['arguments']['hasta'] ?? ''), 0, 10);

        return $desde === $today && $hasta === $today;
    },
    note: 'Dynamic-date injection: the model must resolve "hoy" into today\'s local day range.',
);
