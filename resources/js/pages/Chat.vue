<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import AssistantVisualizer from '@/lib/assistant/AssistantVisualizer.vue';
import type { AssistantVisualState } from '@/lib/assistant/types';
import { useAssistantState } from '@/lib/assistant/useAssistantState';

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
    tool_calls: ToolCallTrace[];
    session_id?: string | null;
    history?: HistoryMessage[];
}

const SESSION_KEY = 'skynet.chat.session_id';

const STATES: readonly AssistantVisualState[] = [
    'idle',
    'processing',
    'listening',
    'speaking',
];

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

const message = ref('');
const sessionId = ref(loadSessionId());
const history = ref<HistoryMessage[]>([]);
const toolCalls = ref<ToolCallTrace[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
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

onMounted(() => {
    void preloadHistory();
});

const { state: assistantState } = useAssistantState({
    chatLoading: loading,
});

const stateLabel = computed(() => assistantState.value);

async function send(): Promise<void> {
    if (message.value.trim() === '' || loading.value) {
        return;
    }

    loading.value = true;
    error.value = null;
    toolCalls.value = [];

    try {
        const response = await fetch('/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                message: message.value,
                session_id: sessionId.value,
            }),
        });

        const payload = await response.json();

        if (!response.ok) {
            error.value =
                payload.message ?? `Request failed (HTTP ${response.status}).`;

            return;
        }

        const data = payload as ChatResponse;

        if (data.session_id) {
            sessionId.value = data.session_id;
            localStorage.setItem(SESSION_KEY, data.session_id);
        }

        history.value = data.history ?? [];
        historyHydrated = true;
        toolCalls.value = data.tool_calls ?? [];
        await scrollToBottom();
    } catch {
        error.value = 'Could not reach the server.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head title="Chat" />
    <NotificationToasts />
    <div
        class="dark hud-frame relative h-screen flex-col items-center bg-hud-base p-6 text-hud-text"
    >
        <main class="flex w-full max-w-2xl flex-col items-center gap-6 pt-10">
            <!-- Top bar: brand + subtitle + readouts -->
            <header class="flex w-full flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-col">
                        <h1
                            class="font-display text-2xl font-semibold tracking-wide text-hud-text"
                        >
                            Skynet Assistant
                        </h1>
                        <p class="text-sm text-hud-text-dim">
                            AEGIS HUD console
                        </p>
                    </div>
                    <a href="/voice" class="text-sm text-hud-text-dim underline"
                        >Voice</a
                    >
                </div>
                <div
                    class="flex flex-wrap gap-x-6 gap-y-2 font-mono text-xs text-hud-text-dim"
                >
                    <span class="flex gap-2">
                        <span class="text-hud-accent">MODELO</span>
                        skynet-aegis-v2
                    </span>
                    <span class="flex gap-2">
                        <span class="text-hud-accent">LATENCIA</span>
                        42ms
                    </span>
                    <span class="flex gap-2">
                        <span class="text-hud-accent">ESTADO</span>
                        {{ stateLabel }}
                    </span>
                </div>
            </header>

            <!-- Orb stage with HUD chrome + passive state buttons -->
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

                    <div class="flex flex-wrap justify-center gap-2">
                        <button
                            v-for="s in STATES"
                            :key="s"
                            type="button"
                            disabled
                            aria-disabled="true"
                            :data-state="s"
                            class="rounded border px-3 py-1 font-mono text-xs tracking-wider uppercase transition-colors"
                            :class="
                                s === assistantState
                                    ? 'border-hud-accent bg-hud-accent text-hud-base'
                                    : 'border-hud-frame text-hud-text-dim'
                            "
                        >
                            {{ s }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Chat bubbles: 78% width, user right / assistant left -->
            <section
                v-if="history.length > 0"
                v-motion
                :initial="{ opacity: 0, y: 8 }"
                :enter="{ opacity: 1, y: 0, transition: { duration: 300 } }"
                ref="threadEl"
                class="max-h flex h-[60vh] min-h-0 w-full flex-1 flex-col gap-4 overflow-y-auto"
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
                        <p v-else class="whitespace-pre-wrap text-hud-text">
                            {{ turn.content }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="toolCalls.length > 0"
                    class="rounded-lg border border-dashed border-hud-frame bg-hud-panel p-4 text-xs"
                >
                    <h2
                        class="mb-2 font-medium tracking-wide text-hud-text-dim uppercase"
                    >
                        Tool call trace
                    </h2>
                    <ol class="flex list-decimal flex-col gap-2 pl-5">
                        <li v-for="(call, index) in toolCalls" :key="index">
                            <span class="font-mono font-semibold">{{
                                call.name
                            }}</span>
                            <span class="mx-1 text-hud-text-dim">with</span>
                            <code>{{ JSON.stringify(call.arguments) }}</code>
                            <span class="mx-1 text-hud-text-dim">→</span>
                            <code>{{ JSON.stringify(call.result) }}</code>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Input bar: passive mic + text input + send -->
            <form
                v-motion
                :initial="{ opacity: 0, y: 8 }"
                :enter="{ opacity: 1, y: 0, transition: { duration: 300 } }"
                class="flex w-full flex-col gap-2 sm:flex-row sm:items-center"
                @submit.prevent="send"
            >
                <div class="flex flex-1 gap-2">
                    <button
                        type="button"
                        disabled
                        aria-disabled="true"
                        aria-label="Voice input is not available here"
                        title="Voice input lives in Voice Chat"
                        class="shrink-0 rounded-lg border border-hud-frame bg-hud-panel px-3 text-hud-text-dim"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-5 w-5"
                            aria-hidden="true"
                        >
                            <path
                                d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"
                            />
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                            <line x1="12" x2="12" y1="19" y2="22" />
                        </svg>
                    </button>
                    <input
                        v-model="message"
                        type="text"
                        placeholder="Ask something..."
                        class="flex-1 rounded-lg border border-hud-frame bg-hud-panel px-4 py-2 text-sm text-hud-text outline-none focus:border-hud-accent"
                        :disabled="loading"
                    />
                </div>
                <button
                    type="submit"
                    class="rounded-lg bg-hud-accent px-5 py-2 text-sm font-medium text-hud-base disabled:opacity-50"
                    :disabled="loading || message.trim() === ''"
                >
                    {{ loading ? 'Thinking...' : 'Send' }}
                </button>
            </form>

            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-100 p-3 text-sm text-red-700"
            >
                {{ error }}
            </p>
        </main>
    </div>
</template>
