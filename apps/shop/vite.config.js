import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Шрифты подключаются локально, из resources/fonts.
            // Плагин bunny() убран намеренно: он ходил за шрифтами
            // на fonts.bunny.net во время сборки, из-за чего сборка
            // падала при недоступности чужого CDN и вообще зависела
            // от сети. Файлы те же, скачаны один раз.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
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
