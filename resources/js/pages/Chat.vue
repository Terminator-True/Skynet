<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ToolCallTrace {
    name: string;
    arguments: Record<string, unknown>;
    result: Record<string, unknown>;
}

interface ChatResponse {
    reply: string;
    tool_calls: ToolCallTrace[];
}

const message = ref('');
const reply = ref<string | null>(null);
const toolCalls = ref<ToolCallTrace[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

async function send(): Promise<void> {
    if (message.value.trim() === '' || loading.value) {
        return;
    }

    loading.value = true;
    error.value = null;
    reply.value = null;
    toolCalls.value = [];

    try {
        const response = await fetch('/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ message: message.value }),
        });

        const payload = await response.json();

        if (!response.ok) {
            error.value = payload.message ?? `Request failed (HTTP ${response.status}).`;

            return;
        }

        const data = payload as ChatResponse;
        reply.value = data.reply;
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
    <div
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <main class="flex w-full max-w-2xl flex-col gap-6 pt-10">
            <h1 class="text-2xl font-semibold">Skynet Assistant</h1>

            <form class="flex gap-2" @submit.prevent="send">
                <input
                    v-model="message"
                    type="text"
                    placeholder="Ask something..."
                    class="flex-1 rounded-lg border border-[#e3e3e0] px-4 py-2 text-sm outline-none focus:border-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615]"
                    :disabled="loading"
                />
                <button
                    type="submit"
                    class="rounded-lg bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                    :disabled="loading || message.trim() === ''"
                >
                    {{ loading ? 'Thinking...' : 'Send' }}
                </button>
            </form>

            <p v-if="error" role="alert" class="rounded-lg bg-red-100 p-3 text-sm text-red-700">
                {{ error }}
            </p>

            <section v-if="reply !== null" class="flex flex-col gap-4">
                <div class="rounded-lg border border-[#e3e3e0] p-4 text-sm dark:border-[#3E3E3A]">
                    <h2 class="mb-1 text-xs font-medium uppercase tracking-wide text-[#706f6c]">Reply</h2>
                    <p>{{ reply }}</p>
                </div>

                <div v-if="toolCalls.length > 0" class="rounded-lg border border-dashed border-[#e3e3e0] p-4 text-xs dark:border-[#3E3E3A]">
                    <h2 class="mb-2 font-medium uppercase tracking-wide text-[#706f6c]">Tool call trace</h2>
                    <ol class="flex list-decimal flex-col gap-2 pl-5">
                        <li v-for="(call, index) in toolCalls" :key="index">
                            <span class="font-mono font-semibold">{{ call.name }}</span>
                            <span class="mx-1 text-[#706f6c]">with</span>
                            <code>{{ JSON.stringify(call.arguments) }}</code>
                            <span class="mx-1 text-[#706f6c]">→</span>
                            <code>{{ JSON.stringify(call.result) }}</code>
                        </li>
                    </ol>
                </div>
            </section>
        </main>
    </div>
</template>
