<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Lee el correo con id 18f3a9c2b7d4e1f0',
    expectedToolName: 'leer_correo',
    semantics: function (array $call): bool {
        // Argument fidelity: the opaque message id must pass through
        // untouched — no truncation, no invented fields.
        return ($call['arguments']['message_id'] ?? null) === '18f3a9c2b7d4e1f0';
    },
    note: 'Fase 3 read path: the model must forward an opaque Gmail id verbatim into leer_correo.',
);
