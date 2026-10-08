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
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Fijar el host evita que el archivo `public/hot` apunte a
        // `http://[::1]:5174`: con el default de Vite 8 la URL se escribe en
        // IPv6, y una página abierta en `http://127.0.0.1:8000` no la puede
        // descargar — el panel queda sin CSS y no se ve por qué.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
