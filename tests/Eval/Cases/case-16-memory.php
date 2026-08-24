<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Recordá que prefiero el café negro sin azúcar',
    expectedToolName: 'recordar_preferencia',
    semantics: function (array $call): bool {
        // The model must pass the preference it was asked to remember as a
        // non-empty string; an empty or blank preferencia defeats the tool.
        $preferencia = trim((string) ($call['arguments']['preferencia'] ?? ''));

        return $preferencia !== '';
    },
    note: 'Fase 6 memory path: the model must call recordar_preferencia when the user explicitly asks to remember a preference, with a non-empty preferencia.',
);
