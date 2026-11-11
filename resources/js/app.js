// Entry de la app Vue + Inertia (superficie cliente / operativa de campo), SEPARADA del admin Filament.
// El admin sigue 100% en Filament (Livewire+Blade); esta SPA convive sin tocarlo.
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    // Título por página: "<página> · DisTrack".
    title: (title) => (title ? `${title} · DisTrack` : 'DisTrack'),

    // Resuelve el componente de página desde resources/js/Pages (carga ansiosa: bundle único, sin code-split
    // por ahora para mantener el build simple).
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Página Inertia no encontrada: ${name}`);
        }
        return page.default;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#E30613', // rojo DisTrack
    },
});
