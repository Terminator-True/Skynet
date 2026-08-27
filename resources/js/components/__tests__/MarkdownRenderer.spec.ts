import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { renderMarkdown } from '@/lib/markdown/renderer';
import MarkdownRenderer from '../MarkdownRenderer.vue';

const projectRoot = process.cwd();

function normalizeHtml(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

describe('MarkdownRenderer', () => {
    it('renders sanitized markdown via v-html after mount', async () => {
        const content = 'Some **bold** text.\n\n# Heading';

        const wrapper = mount(MarkdownRenderer, {
            props: { content },
        });

        expect(wrapper.html()).not.toContain('<strong>');

        await wrapper.vm.$nextTick();

        expect(wrapper.html()).toContain('<strong>bold</strong>');
        expect(wrapper.html()).toContain('<h1>Heading</h1>');

        const rendered = wrapper.get('.markdown-render').element.innerHTML;
        expect(normalizeHtml(rendered)).toBe(
            normalizeHtml(renderMarkdown(content)),
        );
    });

    it('sanitizes dangerous content in v-html', async () => {
        const wrapper = mount(MarkdownRenderer, {
            props: { content: '<script>alert(1)</script>' },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.html()).not.toContain('<script');
        expect(wrapper.html()).not.toContain('<script>');
    });
});

describe('MarkdownRenderer integration', () => {
    it('is referenced by Chat.vue and VoiceChat.vue', () => {
        const chatSource = readFileSync(
            `${projectRoot}/resources/js/pages/Chat.vue`,
            'utf-8',
        );
        const voiceSource = readFileSync(
            `${projectRoot}/resources/js/pages/VoiceChat.vue`,
            'utf-8',
        );

        expect(chatSource).toContain('<MarkdownRenderer');
        expect(voiceSource).toContain('<MarkdownRenderer');
    });
});
