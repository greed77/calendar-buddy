import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

// In Docker on Windows, file events don't cross the bind mount, so poll instead.
const polling = process.env.VITE_POLLING === 'true';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        hmr: { host: 'localhost' },
        watch: {
            usePolling: polling,
            interval: 300,
            ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/node_modules/**'],
        },
    },
});
