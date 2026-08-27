import DOMPurify from 'dompurify';
import hljs from 'highlight.js';
import { marked } from 'marked';

interface CodeToken {
    text: string;
    lang?: string;
}

marked.use({
    renderer: {
        code({ text, lang }: CodeToken): string {
            const useLang = lang && hljs.getLanguage(lang) ? lang : undefined;
            const highlighted = useLang
                ? hljs.highlight(text, { language: useLang }).value
                : hljs.highlightAuto(text).value;
            const className = useLang ? `hljs language-${useLang}` : 'hljs';

            return `<pre><code class="${className}">${highlighted}</code></pre>`;
        },
    },
});

/**
 * Render a plain Markdown string into sanitized, syntax-highlighted HTML.
 *
 * The pipeline runs only in a browser context; when `window` is absent
 * (e.g. `build:ssr` / Inertia SSR) it no-ops so the module never touches
 * the DOM at import time.
 */
export function renderMarkdown(content: string): string {
    if (typeof window === 'undefined') {
        return '';
    }

    const html = marked.parse(content, { async: false });

    return DOMPurify.sanitize(html);
}
