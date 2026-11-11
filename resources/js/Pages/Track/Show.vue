<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

// Datos públicos del rastreo (sin PII), inyectados por el servidor (TrackController).
const props = defineProps({
    tracking: { type: Object, required: true },
    units: { type: Array, default: () => [] },
});

// Tono del estado para el color del badge y la barra.
const tone = computed(() => {
    const s = String(props.tracking.status || '').toUpperCase();
    if (s.includes('DELIVER')) return 'ok';
    if (s.includes('FAIL')) return 'bad';
    if (s.includes('ARRIV') || s.includes('WAY')) return 'live';
    return 'wait';
});

const toneClasses = {
    ok: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
    bad: 'bg-red-500/15 text-red-300 ring-red-500/30',
    live: 'bg-sky-500/15 text-sky-300 ring-sky-500/30',
    wait: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
};

const statusLabel = computed(() => {
    const s = String(props.tracking.status || '').toUpperCase();
    const map = {
        DELIVERED: 'Entregado',
        FAILED: 'No entregado',
        REGISTERED: 'Recibido',
        PREPARED: 'Preparado',
        ON_THE_WAY: 'En camino',
        ARRIVING: 'Llegando',
    };
    return map[s] || (s ? s.replaceAll('_', ' ').toLowerCase() : '—');
});

const unitTone = (status) => {
    const s = String(status || '').toUpperCase();
    if (s.includes('DELIVER') || s.includes('RECEIV')) return 'text-emerald-400';
    if (s.includes('FAIL')) return 'text-red-400';
    return 'text-neutral-400';
};
</script>

<template>
    <Head title="Seguí tu pedido" />

    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <!-- Barra de marca -->
        <header class="border-b border-white/10">
            <div class="mx-auto flex max-w-xl items-center gap-2 px-4 py-4">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg" style="background:#e30613">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.4">
                        <path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7z" /><circle cx="7" cy="17" r="1.6" /><circle cx="17" cy="17" r="1.6" />
                    </svg>
                </span>
                <span class="text-lg font-extrabold tracking-tight">DisTrack</span>
                <span class="ml-auto text-xs text-neutral-400">Seguimiento de tu pedido</span>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-4 py-6">
            <!-- Estado principal -->
            <section class="rounded-2xl border border-white/10 bg-neutral-900/60 p-5 shadow-xl">
                <div class="flex items-center justify-between">
                    <span
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1"
                        :class="toneClasses[tone]"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        {{ statusLabel }}
                    </span>
                    <span
                        v-if="tracking.position_is_live"
                        class="inline-flex items-center gap-1 text-xs text-sky-300"
                    >
                        <span class="h-2 w-2 animate-pulse rounded-full bg-sky-400"></span> En vivo
                    </span>
                </div>

                <p class="mt-4 text-2xl font-bold leading-snug">{{ tracking.stops_message }}</p>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-white/5 p-3">
                        <dt class="text-neutral-400">Paradas restantes</dt>
                        <dd class="mt-0.5 text-lg font-semibold">{{ tracking.remaining_stops ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl bg-white/5 p-3">
                        <dt class="text-neutral-400">Llegada estimada</dt>
                        <dd class="mt-0.5 text-lg font-semibold">{{ tracking.eta || 'Por confirmar' }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Productos -->
            <section class="mt-5">
                <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-neutral-400">Productos del pedido</h2>
                <ul class="mt-2 divide-y divide-white/5 overflow-hidden rounded-2xl border border-white/10 bg-neutral-900/60">
                    <li v-for="(u, i) in units" :key="i" class="flex items-start justify-between gap-3 p-4">
                        <span class="min-w-0 font-medium">{{ u.label }}</span>
                        <span class="shrink-0 text-right text-xs" :class="unitTone(u.status)">{{ u.status_message }}</span>
                    </li>
                    <li v-if="!units.length" class="p-4 text-sm text-neutral-400">Sin detalle de productos.</li>
                </ul>
            </section>

            <p class="mt-6 text-center text-xs text-neutral-500">
                Este enlace es privado y solo muestra el estado de tu entrega.
            </p>
        </main>
    </div>
</template>
