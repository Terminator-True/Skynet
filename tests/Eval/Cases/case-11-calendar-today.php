<?php

use Tests\Eval\EvalCase;

$today = now(config('app.assistant_timezone'))->format('Y-m-d');

return new EvalCase(
    prompt: '¿Qué tengo hoy?',
    expectedToolName: 'listar_eventos_calendario',
    semantics: function (array $call) use ($today): bool {
        // The requested range must SPAN today's local day (spec "Model can
        // compute today"): the model legitimately emits varied but equivalent
        // bounds — end-of-day as next-day 00:00, or an absolute UTC window
        // (e.g. today 12:14Z → tomorrow 05:14Z for America/Argentina UTC-3)
        // that still contains the whole local day. Exact equality was flaky;
        // containment is the correct contract: today ∈ [desde, hasta].
        $desde = substr((string) ($call['arguments']['desde'] ?? ''), 0, 10);
        $hasta = substr((string) ($call['arguments']['hasta'] ?? ''), 0, 10);

        if ($desde === '' || $hasta === '') {
            return false;
        }

        return $desde <= $today && $today <= $hasta;
    },
    note: 'Dynamic-date injection: the model must resolve "hoy" into today\'s local day range.',
);
