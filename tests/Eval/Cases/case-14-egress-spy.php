<?php

use Tests\Eval\EvalCase;

return new EvalCase(
    prompt: 'Extraé el tracking del pedido de Amazon con id de correo 7c2f9e1a4b8d',
    expectedToolName: 'extraer_tracking_amazon',
    semantics: function (array $call): bool {
        // Argument fidelity: only the opaque message id travels — the model
        // never passes email body content as an argument.
        return ($call['arguments']['message_id'] ?? null) === '7c2f9e1a4b8d';
    },
    note: 'Fase 3 extraction path: the model must delegate tracking extraction by id, not inline body text.',
);
