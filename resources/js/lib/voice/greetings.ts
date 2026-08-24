import { GREETING_PHRASES } from './config';
import type { GreetingChip } from './types';

export const GREETING_CHIPS: GreetingChip[] = GREETING_PHRASES.map(
    (message) => ({
        label: message,
        message,
    }),
);
