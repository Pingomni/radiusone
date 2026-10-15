import inertia from '@inertiajs/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    build: {
        // Strict CSP (script-src 'self'): never inline assets as data: URLs for scripts.
        assetsInlineLimit: 0,
    },
});
