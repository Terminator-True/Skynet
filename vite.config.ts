import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500, 600],
                }),
                bunny('Rajdhani', {
                    weights: [500, 600, 700],
                }),
                bunny('Inter', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        proxy: {
            // The voice models (/models/*) are served by the Laravel backend from
            // public/models. onnxruntime-web resolves wasmPaths relative to the JS
            // module origin (the Vite dev server, e.g. [::1]:5173), NOT the page
            // origin — so proxy /models to the Laravel backend in dev.
            '/models': {
                target: process.env.APP_URL ?? 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
});
