import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

// Único build de assets: el tema del panel admin de Filament. La SPA Vue/Inertia fue eliminada;
// el admin es el único frontend.
//
// CERO CDN (incluido el build): las fuentes del tema (Barlow / Barlow Condensed / IBM Plex Mono) están
// AUTO-HOSPEDADAS en resources/fonts y declaradas con @font-face en theme.css. No se usa el plugin de
// fuentes de bunny.net (traía 'Instrument Sans' de un CDN en build y ni siquiera se usaba en el tema).
export default defineConfig({
    plugins: [
        laravel({
            // Tema del admin Filament + Echo + bundle de la app Vue/Inertia (cliente/campo). Inputs
            // independientes: la SPA no toca el tema del admin.
            input: [
                'resources/css/filament/admin/theme.css',
                'resources/js/echo.js',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
