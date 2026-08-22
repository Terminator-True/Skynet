<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Busca correos de Amazon',
    expectedToolName: 'buscar_correos',
    semantics: function (array $call): bool {
        // The query must carry the brand term so Gmail filters the inbox;
        // max_results is optional per schema (default 10).
        $query = strtolower((string) ($call['arguments']['query'] ?? ''));

        return str_contains($query, 'amazon');
    },
    note: 'Fase 3 seam: mail search must win over calendar/dummy tools for an email-domain request.',
);
