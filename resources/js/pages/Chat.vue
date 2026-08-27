<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import AssistantVisualizer from '@/lib/assistant/AssistantVisualizer.vue';
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

function loadSessionId(): string {
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

const { state: assistantState } = useAssistantState({
    chatLoading: loading,
});

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
        toolCalls.value = data.tool_calls ?? [];
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
        class="dark hud-frame relative min-h-screen flex-col items-center bg-hud-base p-6 text-hud-text"
    >
        <main class="flex w-full max-w-2xl flex-col items-center gap-6 pt-10">
            <div class="flex w-full items-center justify-between">
                <h1 class="text-2xl font-semibold">Skynet Assistant</h1>
                <a href="/voice" class="text-sm text-hud-text-dim underline"
                    >Voice</a
                >
            </div>

            <AssistantVisualizer :state="assistantState" />

            <form
                v-motion
                :initial="{ opacity: 0, y: 8 }"
                :enter="{ opacity: 1, y: 0, transition: { duration: 300 } }"
                class="flex w-full gap-2"
                @submit.prevent="send"
            >
                <input
                    v-model="message"
                    type="text"
                    placeholder="Ask something..."
                    class="flex-1 rounded-lg border border-hud-frame bg-hud-panel px-4 py-2 text-sm text-hud-text outline-none focus:border-hud-accent"
                    :disabled="loading"
                />
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

            <section
                v-if="history.length > 0"
                v-motion
                :initial="{ opacity: 0, y: 8 }"
                :enter="{ opacity: 1, y: 0, transition: { duration: 300 } }"
                class="flex w-full flex-col gap-4"
            >
                <div
                    v-for="(turn, index) in history"
                    :key="index"
                    class="rounded-lg border border-hud-frame p-4 text-sm"
                    :class="
                        turn.role === 'user'
                            ? 'bg-[#f3f3f0] dark:bg-[#161615]'
                            : 'bg-hud-panel'
                    "
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
                    <p v-else class="whitespace-pre-wrap">{{ turn.content }}</p>
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
        </main>
    </div>
</template>
