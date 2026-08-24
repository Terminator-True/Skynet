import type { VoiceEngine } from './types';

/**
 * Deterministic fake VoiceEngine for state-machine tests. No real WASM, model
 * download, or audio hardware involved.
 */
export function createFakeEngine(
    overrides: Partial<VoiceEngine> = {},
): VoiceEngine {
    return {
        async transcribe() {
            return 'hola';
        },
        async synthesize(text) {
            return new Blob([text], { type: 'audio/wav' });
        },
        ...overrides,
    };
}
