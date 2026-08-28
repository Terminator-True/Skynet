<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import AssistantVisualizer from '@/lib/assistant/AssistantVisualizer.vue';
import { useAssistantState } from '@/lib/assistant/useAssistantState';
import { GREETING_CHIPS } from '@/lib/voice/greetings';
import { defaultMic } from '@/lib/voice/mic';
import { createPiperEngine } from '@/lib/voice/piperAdapter';
import type { VoiceEngine, WakeWordDetector } from '@/lib/voice/types';
import { useVoiceChat } from '@/lib/voice/useVoiceChat';
import {
    createWhisperService,
    streamWhisperPartials,
} from '@/lib/voice/whisperAdapter';
import { createWhisperWakeWordDetector } from '@/lib/voice/whisperWakeWordDetector';

interface ToolCallTrace {
    name: string;
    arguments: Record<string, unknown>;
    result: Record<string, unknown>;
}

interface HistoryMessage {
    role: string;
    content: string;
    tool_trace?: unknown[] | null;
}

interface ChatResponse {
    reply: string;
    tool_calls?: ToolCallTrace[];
    session_id?: string | null;
    history?: HistoryMessage[];
}

const SESSION_KEY = 'skynet.voice.session_id';

function loadSessionId(): string {
    if (typeof window === 'undefined' || typeof localStorage === 'undefined') {
        return '';
    }

    let id = localStorage.getItem(SESSION_KEY);

    if (!id) {
        id = crypto.randomUUID();
        localStorage.setItem(SESSION_KEY, id);
    }

    return id;
}

const engine = ref<VoiceEngine | null>(null);
const engineFailed = ref(false);
const modelProgress = ref(0);
const textInput = ref('');
const showFallback = ref(false);
const wakeDetector = ref<WakeWordDetector | null>(null);
const sessionId = ref(loadSessionId());
const history = ref<HistoryMessage[]>([]);
const threadEl = ref<HTMLElement | null>(null);

// Guard flag: once a POST response has set history, the onMounted GET preload
// must not overwrite it (the POST is authoritative when the two race).
let historyHydrated = false;

async function scrollToBottom(): Promise<void> {
    await nextTick();

    if (threadEl.value) {
        threadEl.value.scrollTop = threadEl.value.scrollHeight;
    }
}

async function preloadHistory(): Promise<void> {
    try {
        const response = await fetch(
            `/chat/history?session_id=${encodeURIComponent(sessionId.value)}`,
            { method: 'GET', headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            return;
        }

        const payload = (await response.json()) as {
            session_id?: string | null;
            history?: HistoryMessage[];
        };

        if (historyHydrated) {
            return;
        }

        history.value = payload.history ?? [];
        await scrollToBottom();
    } catch {
        // Preload is best-effort; a failed GET should not break the page.
    }
}

const chatPost = async (message: string): Promise<string> => {
    const response = await fetch('/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ message, session_id: sessionId.value }),
    });

    const payload = (await response.json()) as
        ChatResponse | { message?: string };

    if (!response.ok) {
        const error = payload as { message?: string };

        throw new Error(
            error.message ?? `Request failed (HTTP ${response.status}).`,
        );
    }

    const data = payload as ChatResponse;

    if (data.session_id) {
        sessionId.value = data.session_id;
        localStorage.setItem(SESSION_KEY, data.session_id);
    }

    history.value = data.history ?? [];
    historyHydrated = true;
    await scrollToBottom();

    return data.reply;
};

const {
    state,
    status,
    error,
    transcription,
    start,
    stop,
    sendText,
    mode,
    startListening,
    stopListening,
} = useVoiceChat(
    () => engine.value,
    chatPost,
    defaultMic,
    undefined,
    () => wakeDetector.value,
);

// Voice signals for the shared assistant visual state (calling→processing,
// listening→listening, playing→speaking).
const calling = computed(() => state.value === 'calling');
const listening = computed(() => state.value === 'listening');
const playing = computed(() => state.value === 'playing');

const { state: assistantState } = useAssistantState({
    chatLoading: ref(false),
    voice: { calling, listening, playing },
});

const isBusy = computed(
    () =>
        state.value === 'transcribing' ||
        state.value === 'calling' ||
        state.value === 'synthesizing' ||
        state.value === 'playing',
);

const isListening = computed(() => mode.value === 'wake');

const wakeStatus = computed(() => {
    if (state.value === 'wake_detected') {
        return 'Wake word detected — speak now…';
    }

    if (state.value === 'listening') {
        return 'Listening for a wake word…';
    }

    return status.value;
});

function toggleAlwaysListening(): void {
    if (mode.value === 'wake') {
        stopListening();
    } else {
        void startListening();
    }
}

watch(error, (next) => {
    if (next !== null) {
        showFallback.value = true;
    }
});

function sendFallback(): void {
    const message = textInput.value.trim();

    if (message === '') {
        return;
    }

    textInput.value = '';

    void sendText(message);
}

onMounted(() => {
    void preloadHistory();
});

onMounted(async () => {
    try {
        // Real engine wiring: whisper for STT, piper for TTS, exposed through
        // the single VoiceEngine seam the state machine drives. The same
        // whisper service (model loaded once) feeds the wake-word detector.
        const whisper = await createWhisperService((progress) => {
            modelProgress.value = progress;
        });
        const piper = await createPiperEngine();

        engine.value = {
            transcribe: (audio, language, onProgress) => {
                const session = whisper.createSession();
                const segments: string[] = [];

                return (async () => {
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
                })();
            },
            synthesize: (text) => piper.synthesize(text),
        };

        wakeDetector.value = createWhisperWakeWordDetector({
            mic: defaultMic,
            streamText: (audio) => streamWhisperPartials(whisper, audio),
        });
    } catch {
        engineFailed.value = true;
        showFallback.value = true;
    }
});
</script>

<template>
    <Head title="Voice Chat" />
    <NotificationToasts />
    <div
        class="dark hud-frame relative flex h-screen flex-col items-center overflow-hidden bg-hud-base p-6 text-hud-text"
    >
        <main
            class="flex min-h-0 w-full max-w-2xl flex-1 flex-col items-center gap-6 pt-10"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1
                    class="font-display text-2xl font-semibold tracking-wide text-hud-text"
                >
                    Skynet Voice Assistant
                </h1>
                <a href="/chat" class="text-sm text-hud-text-dim underline"
                    >Back to chat</a
                >
            </div>

            <!-- Orb stage with HUD chrome + shared orb states -->
            <section
                class="hud-corner hud-scanline hud-radial-bg relative w-full"
            >
                <span></span>
                <span></span>
                <div class="flex flex-col items-center gap-4 py-8">
                    <p
                        class="font-mono text-xs tracking-[0.3em] text-hud-text-dim uppercase"
                    >
                        System state
                    </p>
                    <AssistantVisualizer :state="assistantState" />
                </div>
            </section>

            <div class="flex flex-col gap-2">
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg border border-hud-frame px-4 py-2 text-sm disabled:opacity-50"
                    :class="
                        isListening
                            ? 'bg-hud-accent text-hud-base'
                            : 'text-hud-text'
                    "
                    :disabled="!engine"
                    @click="toggleAlwaysListening"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="
                            isListening
                                ? 'animate-pulse bg-hud-cyan'
                                : 'bg-hud-text-dim'
                        "
                    ></span>
                    {{
                        isListening
                            ? 'Always listening: ON'
                            : 'Always listening: OFF'
                    }}
                </button>

                <p v-if="isListening" class="text-xs text-hud-text-dim">
                    Wake word detection runs fully on-device — your audio never
                    leaves this device. Your browser shows a recording indicator
                    while the mic is active, and detection only works while this
                    tab is open and focused (browsers pause audio in background
                    tabs).
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chip in GREETING_CHIPS"
                    :key="chip.message"
                    type="button"
                    class="rounded-full border border-hud-frame px-4 py-1.5 text-sm text-hud-text hover:border-hud-accent hover:bg-hud-panel"
                    :disabled="isBusy"
                    @click="sendText(chip.message)"
                >
                    {{ chip.label }}
                </button>
            </div>

            <div v-if="showFallback" class="flex gap-2">
                <input
                    v-model="textInput"
                    type="text"
                    placeholder="Type a message..."
                    class="flex-1 rounded-lg border border-hud-frame bg-hud-panel px-4 py-2 text-sm text-hud-text outline-none focus:border-hud-accent"
                    :disabled="isBusy"
                    @keydown.enter.prevent="sendFallback"
                />
                <button
                    type="button"
                    class="rounded-lg bg-hud-accent px-5 py-2 text-sm font-medium text-hud-base disabled:opacity-50"
                    :disabled="isBusy || textInput.trim() === ''"
                    @click="sendFallback"
                >
                    Send
                </button>
            </div>

            <button
                v-if="!isListening"
                type="button"
                class="rounded-lg bg-hud-accent px-6 py-4 text-sm font-medium text-hud-base disabled:opacity-50"
                :disabled="!engine || isBusy"
                @mousedown="start()"
                @mouseup="stop()"
                @mouseleave="stop()"
            >
                {{ engine ? status : `Loading engine… ${modelProgress}%` }}
            </button>

            <div
                v-else
                class="rounded-lg border border-hud-frame bg-hud-panel px-6 py-4 text-sm text-hud-text"
                aria-live="polite"
            >
                {{ wakeStatus }}
            </div>

            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-100 p-3 text-sm text-red-700"
            >
                {{ error }}
            </p>

            <section v-if="transcription" class="flex flex-col gap-4">
                <div
                    class="rounded-lg border border-hud-frame bg-hud-panel p-4 text-sm"
                >
                    <h2
                        class="mb-1 text-xs font-medium tracking-wide text-hud-text-dim uppercase"
                    >
                        You said
                    </h2>
                    <p>{{ transcription }}</p>
                </div>
            </section>

            <section
                v-if="history.length > 0"
                ref="threadEl"
                class="flex min-h-0 w-full flex-1 flex-col gap-4 overflow-y-auto"
            >
                <div
                    v-for="(turn, index) in history"
                    :key="index"
                    :data-role="turn.role"
                    class="w-[78%] max-w-full"
                    :class="turn.role === 'user' ? 'ml-auto' : 'mr-auto'"
                >
                    <div
                        class="rounded-lg border border-hud-frame bg-hud-panel p-4 text-sm"
                    >
                        <h2
                            class="mb-1 text-xs font-medium tracking-wide text-hud-text-dim uppercase"
                        >
                            {{ turn.role === 'user' ? 'You' : 'Assistant' }}
                        </h2>
                        <MarkdownRenderer
                            v-if="turn.role !== 'user'"
                            :content="turn.content"
                        />
                        <p v-else class="whitespace-pre-wrap">
                            {{ turn.content }}
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
