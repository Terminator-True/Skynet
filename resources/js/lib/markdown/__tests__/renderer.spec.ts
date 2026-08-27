import { describe, expect, it } from 'vitest';
import { renderMarkdown } from '../renderer';

describe('renderMarkdown', () => {
    it('renders rich markdown constructs into formatted HTML', () => {
        const source = [
            'Intro paragraph.',
            '',
            '# Heading',
            '',
            'Some **bold** and `inline code`.',
            '',
            '1. first',
            '2. second',
            '',
            '- alpha',
            '- beta',
            '',
            '| A | B |',
            '| --- | --- |',
            '| 1 | 2 |',
            '',
            '[a link](https://example.com)',
            '',
            '```js',
            'const x = 1;',
            '```',
        ].join('\n');

        const html = renderMarkdown(source);

        expect(html).toContain('<h1>Heading</h1>');
        expect(html).toContain('<strong>bold</strong>');
        expect(html).toContain('<code>inline code</code>');
        expect(html).toContain('<ol>');
        expect(html).toContain('<li>first</li>');
        expect(html).toContain('<ul>');
        expect(html).toContain('<li>alpha</li>');
        expect(html).toContain('<table>');
        expect(html).toContain('<th>A</th>');
        expect(html).toContain('<td>1</td>');
        expect(html).toContain('<a href="https://example.com">a link</a>');
        expect(html).toContain('<pre><code');
        expect(html).toContain('hljs-keyword');
    });

    it('renders deterministic HTML for a given input', () => {
        const source = 'Hello **world**!';

        // happy-dom's DOMPurify serialization drops the single top-level <p>
        // wrapper; the golden below captures the rendered inner formatting and
        // is stable across repeated runs.
        expect(renderMarkdown(source)).toBe('Hello <strong>world</strong>!\n');
        expect(renderMarkdown(source)).toBe(renderMarkdown(source));
    });

    it('strips script tags', () => {
        const html = renderMarkdown('<script>alert(1)</script>');

        expect(html).not.toContain('<script');
        expect(html).not.toContain('<script>');
    });

    it('strips onerror event attributes', () => {
        const html = renderMarkdown('<img src="x" onerror="alert(1)">');

        expect(html).not.toContain('onerror');
        expect(html).not.toContain('alert(1)');
    });

    it('preserves benign strong and anchor tags', () => {
        const html = renderMarkdown(
            '<strong>keep me</strong> <a href="https://a.b">go</a>',
        );

        expect(html).toContain('<strong>keep me</strong>');
        expect(html).toContain('<a href="https://a.b">go</a>');
    });

    it('tolerates partial markdown without throwing or leaking raw HTML', () => {
        expect(() => renderMarkdown('```js\nconst open = ')).not.toThrow();
        expect(() => renderMarkdown('**unbalanced')).not.toThrow();

        const fence = renderMarkdown('```js\nconst x = 1;\n');
        expect(fence).toContain('<code');
        expect(fence).toContain('hljs');

        const bold = renderMarkdown('**unbalanced');
        expect(bold).not.toContain('<');
    });

    it('adds hljs spans and the language class to fenced code blocks', () => {
        const html = renderMarkdown('```js\nconst x = 1;\n```');

        expect(html).toContain('language-js');
        expect(html).toContain('hljs');
        expect(html).toContain('<span');
    });

    it('returns an empty string when window is undefined', () => {
        const originalWindow = globalThis.window;
        // @ts-expect-error simulate SSR environment
        globalThis.window = undefined;

        try {
            expect(renderMarkdown('**bold**')).toBe('');
        } finally {
            globalThis.window = originalWindow;
        }
    });
});
