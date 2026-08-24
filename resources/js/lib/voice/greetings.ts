import type { GreetingChip } from './types';

const GREETING_PHRASES = [
    'Hola',
    '¿Qué tengo hoy?',
    '¿Cuál es la capital de Francia?',
] as const;

export const GREETING_CHIPS: GreetingChip[] = GREETING_PHRASES.map(
    (message) => ({
        label: message,
        message,
    }),
);
