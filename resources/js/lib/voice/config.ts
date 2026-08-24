/** Whisper STT model served locally from public/models (gitignored). Never remote. */
export const WHISPER_MODEL = 'tiny';
export const WHISPER_MODEL_FILE = '/models/ggml-tiny.bin';
export const VOICE_LANGUAGE = 'es';
export const TARGET_SAMPLE_RATE = 16000;

/** Piper TTS voice (Spanish) served locally from public/models/piper. Never remote. */
export const PIPER_VOICE = 'es_ES-sharvard-medium';
export const PIPER_VOICE_FALLBACK = 'es_ES-mls_9972-low';

/** Local runtime WASM paths for piper-tts-web (onnx runtime + piper phonemize). */
export const PIPER_WASM_PATHS = {
    onnxWasm: '/models/piper/onnx/',
    piperData: '/models/piper/wasm/piper_phonemize.data',
    piperWasm: '/models/piper/wasm/piper_phonemize.wasm',
} as const;

export const GREETING_PHRASES = [
    'Hola',
    '¿Qué tengo hoy?',
    '¿Cuál es la capital de Francia?',
] as const;

/**
 * Hands-free wake-word configuration. Everything here is tunable without code
 * changes. Wake detection stays fully local (whisper.wasm) — no new dependency.
 */

/** Wake phrases recognized to start hands-free listening. */
export const WAKE_PHRASES = ['Oye Skynet', 'Hola Skynet'] as const;

/** RMS energy gate threshold (0..1); audio below this is treated as silence. */
export const WAKE_SENSITIVITY = 0.02;

/** Rolling audio buffer (ms) fed to whisper for wake-phrase matching. */
export const WAKE_BUFFER_MS = 2000;

/** Whether always-listening starts enabled by default (feature-flag off for a silent revert). */
export const LISTEN_ENABLED_DEFAULT = false;

/**
 * Utterance-end contract after a wake: the detector ends the captured utterance
 * after this much trailing silence, or caps it at the maximum duration.
 */
export const WAKE_UTTERANCE_SILENCE_MS = 800;
export const WAKE_UTTERANCE_MAX_MS = 15000;
