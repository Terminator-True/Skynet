import { ModelManager, WhisperWasmService } from '@timur00kh/whisper.wasm';
import { VOICE_LANGUAGE, WHISPER_MODEL_FILE } from './config';
import type { VoiceEngine } from './types';

/**
 * Loads a whisper.wasm service with the model served exclusively from the
 * local `/models/*` path (never a remote/HuggingFace URL), cached in
 * IndexedDB by ModelManager using the URL as the cache key. Shared by the
 * STT engine and the wake-word detector so the model is loaded exactly once.
 */
export async function createWhisperService(
    onModelProgress?: (progress: number) => void,
): Promise<WhisperWasmService> {
    const whisper = new WhisperWasmService({ logLevel: 1 });
    const models = new ModelManager({ logLevel: 1 });

    if (!(await whisper.checkWasmSupport())) {
        throw new Error('WebAssembly is not supported.');
    }

    const modelData = await models.loadModelByUrl(
        WHISPER_MODEL_FILE,
        onModelProgress,
    );

    await whisper.initModel(modelData);

    return whisper;
}

/** Streams normalized non-empty partial transcript lines for a given buffer. */
export async function* streamWhisperPartials(
    whisper: WhisperWasmService,
    audio: Float32Array,
    language = VOICE_LANGUAGE,
): AsyncIterable<string> {
    const session = whisper.createSession();

    for await (const segment of session.streaming(audio, { language })) {
        const text = segment.text.trim();

        if (text.length > 0) {
            yield text;
        }
    }
}

/**
 * Builds a VoiceEngine whose STT uses whisper.wasm with a model served
 * exclusively from the local `/models/*` path (never a remote/HuggingFace
 * URL), cached in IndexedDB by ModelManager using the URL as the cache key.
 */
export async function createWhisperEngine(
    onModelProgress?: (progress: number) => void,
): Promise<VoiceEngine> {
    const whisper = await createWhisperService(onModelProgress);

    return {
        async transcribe(audio, language = VOICE_LANGUAGE, onProgress) {
            const session = whisper.createSession();
            const segments: string[] = [];

            for await (const segment of session.streaming(audio, {
                language,
            })) {
                const text = segment.text.trim();

                if (text.length > 0) {
                    segments.push(text);
                }

                onProgress?.(100);
            }

            return segments.join(' ').trim();
        },
        // TTS is provided by piperAdapter (Slice B); this engine only transcribes.
        async synthesize() {
            throw new Error('Whisper engine does not synthesize.');
        },
    };
}
