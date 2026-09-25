import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Die gebauten Dateien (public/build) werden committet. Auf dem Server läuft kein Node.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/filament/crm/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
