<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { GREETING_CHIPS } from '@/lib/voice/greetings';
import { defaultMic } from '@/lib/voice/mic';
import { createPiperEngine } from '@/lib/voice/piperAdapter';
import type { VoiceEngine } from '@/lib/voice/types';
import { useVoiceChat } from '@/lib/voice/useVoiceChat';
import { createWhisperEngine } from '@/lib/voice/whisperAdapter';

interface ToolCallTrace {
    name: string;
    arguments: Record<string, unknown>;
    result: Record<string, unknown>;
}

interface ChatResponse {
    reply: string;
    tool_calls?: ToolCallTrace[];
}

const engine = ref<VoiceEngine | null>(null);
const engineFailed = ref(false);
const modelProgress = ref(0);
const textInput = ref('');
const showFallback = ref(false);

const chatPost = async (message: string): Promise<string> => {
    const response = await fetch('/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ message }),
    });

    const payload = (await response.json()) as
        ChatResponse | { message?: string };

    if (!response.ok) {
        const error = payload as { message?: string };

        throw new Error(
            error.message ?? `Request failed (HTTP ${response.status}).`,
        );
    }

    return (payload as ChatResponse).reply;
};

const { state, status, error, transcription, reply, start, stop, sendText } =
    useVoiceChat(() => engine.value, chatPost, defaultMic);

const isBusy = computed(
    () =>
        state.value === 'transcribing' ||
        state.value === 'calling' ||
        state.value === 'synthesizing' ||
        state.value === 'playing',
);

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

onMounted(async () => {
    try {
        // Real engine wiring: whisper for STT, piper for TTS, exposed through
        // the single VoiceEngine seam the state machine drives.
        const whisper = await createWhisperEngine((progress) => {
            modelProgress.value = progress;
        });
        const piper = await createPiperEngine();

        engine.value = {
            transcribe: (audio, language, onProgress) =>
                whisper.transcribe(audio, language, onProgress),
            synthesize: (text) => piper.synthesize(text),
        };
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
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <main class="flex w-full max-w-2xl flex-col gap-6 pt-10">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">Skynet Voice Assistant</h1>
                <a href="/chat" class="text-sm text-[#706f6c] underline"
                    >Back to chat</a
                >
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chip in GREETING_CHIPS"
                    :key="chip.message"
                    type="button"
                    class="rounded-full border border-[#e3e3e0] px-4 py-1.5 text-sm hover:bg-[#f3f3f0] dark:border-[#3E3E3A] dark:hover:bg-[#161615]"
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
                    class="flex-1 rounded-lg border border-[#e3e3e0] px-4 py-2 text-sm outline-none focus:border-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615]"
                    :disabled="isBusy"
                    @keydown.enter.prevent="sendFallback"
                />
                <button
                    type="button"
                    class="rounded-lg bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    :disabled="isBusy || textInput.trim() === ''"
                    @click="sendFallback"
                >
                    Send
                </button>
            </div>

            <button
                v-else
                type="button"
                class="rounded-lg bg-[#1b1b18] px-6 py-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                :disabled="!engine || isBusy"
                @mousedown="start()"
                @mouseup="stop()"
                @mouseleave="stop()"
            >
                {{ engine ? status : `Loading engine… ${modelProgress}%` }}
            </button>

            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-100 p-3 text-sm text-red-700"
            >
                {{ error }}
            </p>

            <section v-if="transcription" class="flex flex-col gap-4">
                <div
                    class="rounded-lg border border-[#e3e3e0] p-4 text-sm dark:border-[#3E3E3A]"
                >
                    <h2
                        class="mb-1 text-xs font-medium tracking-wide text-[#706f6c] uppercase"
                    >
                        You said
                    </h2>
                    <p>{{ transcription }}</p>
                </div>
            </section>

            <section v-if="reply !== null" class="flex flex-col gap-4">
                <div
                    class="rounded-lg border border-[#e3e3e0] p-4 text-sm dark:border-[#3E3E3A]"
                >
                    <h2
                        class="mb-1 text-xs font-medium tracking-wide text-[#706f6c] uppercase"
                    >
                        Reply
                    </h2>
                    <p>{{ reply }}</p>
                </div>
            </section>
        </main>
    </div>
</template>
