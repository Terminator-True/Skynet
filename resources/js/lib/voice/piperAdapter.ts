import type * as PiperTts from '@mintplex-labs/piper-tts-web';
import { PIPER_VOICE, PIPER_WASM_PATHS } from './config';
import type { VoiceEngine } from './types';

let modulePromise: Promise<typeof PiperTts> | null = null;

/** Lazy-load the heavy piper-tts-web module on first use (keeps initial bundle lean). */
function loadPiper(): Promise<typeof PiperTts> {
    modulePromise ??= import('@mintplex-labs/piper-tts-web');

    return modulePromise;
}

/**
 * Builds a VoiceEngine whose TTS uses piper-tts-web. The voice ONNX + config and
 * the runtime WASM are all served from the local `/models/piper/*` path — the
 * package's `HF_BASE` const is patched (patch-package) so it never fetches a
 * model from HuggingFace at runtime.
 */
export async function createPiperEngine(
    onProgress?: (progress: number) => void,
): Promise<VoiceEngine> {
    const piper = await loadPiper();
    const session = await piper.TtsSession.create({
        voiceId: PIPER_VOICE,
        wasmPaths: PIPER_WASM_PATHS,
        progress: (p) => {
            if (p.url !== 'tts://inference-progress') {
                onProgress?.(100);
            }
        },
    });

    return {
        // STT is provided by whisperAdapter; this engine only synthesizes.
        async transcribe() {
            throw new Error('Piper engine does not transcribe.');
        },
        async synthesize(text) {
            return session.predict(text);
        },
    };
}
