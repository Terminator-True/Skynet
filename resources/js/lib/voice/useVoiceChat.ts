import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { VOICE_LANGUAGE } from './config';
import type { ChatPost, MicOps, VoiceChatState, VoiceEngine } from './types';

const STATUS_LABELS: Record<VoiceChatState, string> = {
    idle: 'Press and hold to talk',
    recording: 'Recording…',
    transcribing: 'Transcribing…',
    calling: 'Asking the assistant…',
    synthesizing: 'Preparing the reply…',
    playing: 'Playing the reply…',
    error: 'Something went wrong',
    unsupported: 'Voice input is not supported',
};

export interface UseVoiceChat {
    state: Ref<VoiceChatState>;
    status: ComputedRef<string>;
    error: Ref<string | null>;
    transcription: Ref<string>;
    reply: Ref<string | null>;
    progress: Ref<number>;
    start: () => Promise<void>;
    stop: () => Promise<void>;
    sendText: (message: string) => Promise<void>;
}

/** Play a synthesized WAV Blob through an <audio> element. */
function defaultPlayAudio(blob: Blob): Promise<void> {
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);

    return new Promise<void>((resolve, reject) => {
        audio.onended = () => {
            URL.revokeObjectURL(url);
            resolve();
        };
        audio.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Audio playback failed.'));
        };
        void audio.play().catch(() => {
            URL.revokeObjectURL(url);
            reject(new Error('Audio playback failed.'));
        });
    });
}

/**
 * Pure state-machine composable for voice chat. `getEngine`, `chatPost`, and
 * `mic` are injected so the transitions stay deterministic and testable
 * without real WASM or hardware. `playAudio` defaults to <audio> playback and
 * is injectable so tests can observe the synthesize→play→idle transitions.
 */
export function useVoiceChat(
    getEngine: () => VoiceEngine | null,
    chatPost: ChatPost,
    mic: MicOps,
    playAudio: (blob: Blob) => Promise<void> = defaultPlayAudio,
): UseVoiceChat {
    const state = ref<VoiceChatState>('idle');
    const error = ref<string | null>(null);
    const transcription = ref('');
    const reply = ref<string | null>(null);
    const progress = ref(0);
    const micStream = ref<MediaStream | null>(null);

    const status = computed(() => STATUS_LABELS[state.value] ?? state.value);

    function requireEngine(): VoiceEngine {
        const engine = getEngine();

        if (engine === null) {
            throw new Error('Voice engine is not ready.');
        }

        return engine;
    }

    async function start(): Promise<void> {
        if (state.value === 'recording') {
            return;
        }

        error.value = null;
        transcription.value = '';
        reply.value = null;
        progress.value = 0;

        try {
            requireEngine();

            micStream.value = await mic.request();

            state.value = 'recording';
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Microphone unavailable.';

            state.value = 'error';
        }
    }

    async function stop(): Promise<void> {
        if (state.value !== 'recording') {
            return;
        }

        state.value = 'transcribing';

        try {
            const engine = requireEngine();
            const stream = micStream.value;

            if (stream === null) {
                throw new Error('No active microphone.');
            }

            const audio = await mic.capture(stream);

            mic.release();
            micStream.value = null;

            const text = await engine.transcribe(audio, VOICE_LANGUAGE, (p) => {
                progress.value = p;
            });

            const trimmed = text.trim();

            if (trimmed.length === 0) {
                state.value = 'idle';

                return;
            }

            transcription.value = trimmed;

            await sendText(trimmed);
        } catch (e) {
            mic.release();
            micStream.value = null;
            error.value =
                e instanceof Error ? e.message : 'Transcription failed.';

            state.value = 'error';
        }
    }

    async function sendText(message: string): Promise<void> {
        state.value = 'calling';

        try {
            const replyText = await chatPost(message);

            reply.value = replyText;

            const engine = getEngine();

            // Text fallback / greeting without a voice engine: show the text
            // reply only. When an engine is present, synthesize and speak it.
            if (engine === null) {
                state.value = 'idle';

                return;
            }

            state.value = 'synthesizing';

            const wav = await engine.synthesize(replyText);

            state.value = 'playing';
            await playAudio(wav);

            state.value = 'idle';
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Request failed.';

            state.value = 'error';
        }
    }

    return {
        state,
        status,
        error,
        transcription,
        reply,
        progress,
        start,
        stop,
        sendText,
    };
}
