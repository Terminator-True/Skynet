<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { renderMarkdown } from '@/lib/markdown/renderer';

const props = defineProps<{ content: string }>();

const hydrated = ref(false);

onMounted(() => {
    hydrated.value = true;
});

const renderedHtml = computed(() => {
    if (!hydrated.value) {
        return '';
    }

    return renderMarkdown(props.content);
});
</script>

<template>
    <div class="markdown-render" v-html="renderedHtml"></div>
</template>
