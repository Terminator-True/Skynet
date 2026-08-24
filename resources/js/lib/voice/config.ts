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
