import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // Enable minification (using esbuild - faster than terser)
        minify: 'esbuild',
        // Enable CSS code splitting
        cssCodeSplit: true,
        // Set chunk size warning limit
        chunkSizeWarningLimit: 1000,
        // Optimize dependencies
        reportCompressedSize: false, // Faster builds
    },
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
