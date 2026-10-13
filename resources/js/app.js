import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from './Layouts/AppLayout.vue';

// Entry de la app web Vue (Inertia). Monta el SPA: sin recargas de página, navegación por el router de
// Inertia, barra de progreso superior en rojo Dismac. El panel admin Filament (/admin) es aparte.
const appName = import.meta.env.VITE_APP_NAME || 'Dismac Logistics';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const page = resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        );

        // Layout por defecto para todas las páginas (se puede sobreescribir por página con
        // `defineOptions({ layout: ... })`). Layout persistente = no se re-monta entre navegaciones.
        page.then((module) => {
            module.default.layout = module.default.layout ?? AppLayout;
        });

        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        // Barra de progreso superior (la sensación "app web" que pidió el dueño).
        color: '#E2231A',
        showSpinner: false,
    },
});
