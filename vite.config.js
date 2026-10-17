import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Plus Jakarta Sans', {
                    weights: [400, 600, 700],
                    preload: [{ weight: 400 }, { weight: 700 }],
                }),
                // Teks Arab (nama lembaga, kutipan footer): hanya diunduh browser bila glyph Arab dipakai.
                bunny('Noto Naskh Arabic', {
                    weights: [400, 700],
                    subsets: ['arabic'],
                    preload: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
