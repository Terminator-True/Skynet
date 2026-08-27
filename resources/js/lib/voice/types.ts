/**
 * Contract seam for the voice assistant. Slice A ships a whisper.wasm STT
 * implementation; piper TTS arrives in Slice B.
 */
export interface VoiceEngine {
    transcribe(
        audio: Float32Array,
        language: string,
        onProgress?: (progress: number) => void,
    ): Promise<string>;
    synthesize(text: string): Promise<Blob>;
}

export type VoiceChatState =
    | 'idle'
    | 'listening'
    | 'recording'
    | 'wake_detected'
    | 'transcribing'
    | 'calling'
    | 'synthesizing'
    | 'playing'
    | 'error'
    | 'unsupported';

export type ChatPost = (message: string) => Promise<string>;

/** Manual push-to-talk vs hands-free always-listening. */
export type VoiceMode = 'push' | 'wake';

export interface GreetingChip {
    label: string;
    message: string;
}

/** Injectable mic operations so the state machine stays pure and testable. */
export interface MicOps {
    request(): Promise<MediaStream>;
    release(): void;
    capture(stream: MediaStream): Promise<Float32Array>;
    /**
     * Streams whisper-ready 16kHz Float32Array chunks to `onChunk` as they
     * arrive (used to feed a wake-word detector). Returns a cleanup function
     * that stops the capture. The PTT `capture()` path is left untouched.
     */
    startCapture(
        stream: MediaStream,
        onChunk: (chunk: Float32Array) => void,
    ): () => void;
}

/**
 * Seam for hands-free wake-word detection. The detector owns the audio
 * pipeline (energy gate → whisper partials → phrase match) and signals a wake
 * via `onWake`; once a wake is recognized it assembles the following utterance
 * and hands the audio to `onUtterance` (utterance-end is decided by the
 * detector, e.g. a silence gap plus a max-duration cap). `suspend`/`resume`
 * enable the audio-feedback-loop guard: while the assistant is speaking its own
 * reply, the detector pauses so it never transcribes its own voice.
 */
export interface WakeWordDetector {
    start(
        stream: MediaStream,
        handlers: {
            onWake(): void;
            onUtterance(audio: Float32Array): void;
        },
    ): void;
    stop(): void;
    suspend(): void;
    resume(): void;
}
