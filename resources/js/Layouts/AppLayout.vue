<script setup>
// Shell de la app web (SPA). Barra lateral de navegación + top bar + área de contenido. Navegación con
// <Link> de Inertia (sin recargar). El layout es persistente: no se re-monta entre páginas. Este es el
// patrón base que replican todas las pantallas operativas.
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => user.value?.roles ?? []);

// Ítems de navegación. `roles` vacío = visible para cualquier usuario autenticado; si se listan roles,
// solo se muestra a quien tenga alguno (gating fino se afina al cablear RBAC por módulo).
// Navegación de la app operativa. Un ítem por módulo del SPA. Los agentes de cada módulo crean sus
// páginas/rutas; el ítem ya está aquí para no tocar este layout compartido. `routeName` debe existir
// (Ziggy): si una ruta aún no está registrada, isActive la ignora sin romper.
const nav = [
    { label: 'Panel', routeName: 'app.dashboard', roles: [] },
    { label: 'Torre de control', routeName: 'app.control-tower.index', roles: [] },
    { label: 'Planificación', routeName: 'app.planning.index', roles: [] },
    { label: 'Rutas', routeName: 'app.routes.index', roles: [] },
    { label: 'Entregas', routeName: 'app.deliveries.index', roles: [] },
    { label: 'Pedidos', routeName: 'app.shipments.index', roles: [] },
    { label: 'Seguimiento', routeName: 'app.tracking.index', roles: [] },
    { label: 'Personal', routeName: 'app.personnel.index', roles: [] },
];

// Solo muestra ítems cuya ruta ya exista en Ziggy (módulos aún no construidos no aparecen rotos).
const routeExists = (name) => {
    try {
        return typeof route().has === 'function' ? route().has(name) : true;
    } catch (e) {
        return false;
    }
};

const visibleNav = computed(() =>
    nav.filter((item) =>
        routeExists(item.routeName)
        && (item.roles.length === 0 || item.roles.some((r) => roles.value.includes(r))),
    ),
);

const isActive = (routeName) => {
    try {
        return route().current(routeName) || route().current(routeName.replace(/\.index$/, '') + '.*');
    } catch (e) {
        return false;
    }
};

const initials = computed(() => {
    const name = user.value?.name ?? '?';
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});

// Mensajes flash globales (los comparte HandleInertiaRequests como props.flash). Cualquier módulo que
// haga una escritura y redirija con ->with('success'|'error', ...) obtiene feedback visible sin tener que
// renderizarlo en cada página.
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);
const dismissed = ref(false);
watch(() => [flashSuccess.value, flashError.value], () => { dismissed.value = false; });
const flash = computed(() => {
    if (dismissed.value) {
        return null;
    }
    if (flashError.value) {
        return { type: 'error', message: flashError.value };
    }
    if (flashSuccess.value) {
        return { type: 'success', message: flashSuccess.value };
    }
    return null;
});

// El botón "Salir" aparece solo cuando existe la ruta de logout del SPA (Ziggy).
const logoutAvailable = computed(() => routeExists('app.logout'));
const logout = () => {
    if (logoutAvailable.value) {
        router.post(route('app.logout'));
    }
};
</script>

<template>
    <div class="flex min-h-screen bg-neutral-950 text-neutral-100">
        <!-- Barra lateral -->
        <aside class="hidden w-60 shrink-0 flex-col border-r border-neutral-800 bg-neutral-900/60 md:flex">
            <div class="flex h-14 items-center gap-2 border-b border-neutral-800 px-4">
                <span class="inline-block h-6 w-6 rounded bg-[#E2231A]"></span>
                <span class="font-semibold tracking-tight">Dismac Logistics</span>
            </div>
            <nav class="flex-1 space-y-1 p-3">
                <Link
                    v-for="item in visibleNav"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                    :class="isActive(item.routeName)
                        ? 'bg-[#E2231A]/15 text-white'
                        : 'text-neutral-400 hover:bg-neutral-800/60 hover:text-neutral-100'"
                >
                    <span
                        class="inline-block h-1.5 w-1.5 rounded-full"
                        :class="isActive(item.routeName) ? 'bg-[#E2231A]' : 'bg-neutral-600'"
                    ></span>
                    {{ item.label }}
                </Link>
            </nav>
            <div class="border-t border-neutral-800 p-3">
                <a
                    href="/admin"
                    class="block rounded-lg px-3 py-2 text-xs text-neutral-500 transition hover:bg-neutral-800/60 hover:text-neutral-300"
                >
                    Ir al admin (Filament) →
                </a>
            </div>
        </aside>

        <!-- Columna principal -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center justify-between border-b border-neutral-800 bg-neutral-900/40 px-4 md:px-6">
                <div class="flex items-center gap-3">
                    <span class="font-semibold md:hidden">Dismac Logistics</span>
                    <slot name="header" />
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden text-sm text-neutral-400 sm:inline">{{ user?.name }}</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-[#E2231A]/20 text-xs font-semibold text-[#ff8a80]">
                        {{ initials }}
                    </span>
                    <button
                        v-if="logoutAvailable"
                        type="button"
                        class="rounded-lg border border-neutral-700 px-2.5 py-1.5 text-xs text-neutral-400 transition hover:bg-neutral-800 hover:text-neutral-200"
                        @click="logout"
                    >
                        Salir
                    </button>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto p-4 md:p-6">
                <!-- Flash global de escrituras (éxito/error), descartable. -->
                <div
                    v-if="flash"
                    class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm"
                    :class="flash.type === 'error'
                        ? 'border-[#E2231A]/40 bg-[#E2231A]/10 text-[#ff8a80]'
                        : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'"
                >
                    <span>{{ flash.message }}</span>
                    <button type="button" class="text-neutral-400 transition hover:text-neutral-200" @click="dismissed = true">✕</button>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
