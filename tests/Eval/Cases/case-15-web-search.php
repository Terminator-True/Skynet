<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: '¿Cuál es la capital de Francia?',
    expectedToolName: 'buscar_web',
    semantics: function (array $call): bool {
        // The query must carry a real factual term so the DDG/Wikipedia flow
        // can resolve a card; an empty or generic consulta would defeat it.
        $consulta = trim((string) ($call['arguments']['consulta'] ?? ''));

        return $consulta !== '';
    },
    note: 'Fase 4 web path: the model must delegate a factual/encyclopedic question to buscar_web with a non-empty consulta.',
);
