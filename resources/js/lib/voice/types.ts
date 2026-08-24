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
    | 'recording'
    | 'transcribing'
    | 'calling'
    | 'synthesizing'
    | 'playing'
    | 'error'
    | 'unsupported';

export type ChatPost = (message: string) => Promise<string>;

export interface GreetingChip {
    label: string;
    message: string;
}

/** Injectable mic operations so the state machine stays pure and testable. */
export interface MicOps {
    request(): Promise<MediaStream>;
    release(): void;
    capture(stream: MediaStream): Promise<Float32Array>;
}
