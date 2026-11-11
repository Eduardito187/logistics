<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    hasRoute: { type: Boolean, default: false },
    driverName: { type: String, default: 'Chofer' },
    plate: { type: String, default: '—' },
    status: { type: String, default: '' },
    stops: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ total: 0, delivered: 0, pending: 0 }) },
    routeLine: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const logout = () => router.post('/app/logout');

// Inicia la ruta: marca la salida de todos los pedidos → «en camino» en el seguimiento del cliente.
const startRoute = () => {
    if (!window.confirm('¿Iniciar la ruta? Tus pedidos pasarán a «en camino».')) return;
    router.post('/app/driver/route/start', {}, { preserveScroll: true });
};

// Abre el mapa de navegación del teléfono hacia la parada.
const navigate = (stop) => {
    if (stop.lat == null || stop.lng == null) return;
    window.open(`https://www.openstreetmap.org/?mlat=${stop.lat}&mlon=${stop.lng}#map=17/${stop.lat}/${stop.lng}`, '_blank');
};

// Confirma la entrega (prueba de entrega real por el dominio) con el nombre de quien recibió.
const confirmDeliver = (stop) => {
    const who = window.prompt('¿Quién recibió el pedido? (nombre y apellido)');
    if (!who?.trim()) return;
    router.post(
        `/app/driver/stops/${stop.shipment_id}/deliver`,
        { confirmation: who.trim(), lat: stop.lat, lng: stop.lng },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head><title>Mi ruta de hoy</title></Head>

    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <!-- Cabecera -->
        <header class="sticky top-0 z-10 border-b border-white/10 bg-neutral-950/90 backdrop-blur">
            <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-3">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg" style="background:#e30613">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.4">
                        <path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7z" /><circle cx="7" cy="17" r="1.6" /><circle cx="17" cy="17" r="1.6" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold leading-tight">{{ driverName }}</p>
                    <p class="text-xs text-neutral-400">Camión {{ plate }}</p>
                </div>
                <button class="ml-auto rounded-lg border border-white/10 px-3 py-1.5 text-xs text-neutral-300 hover:bg-white/5" @click="logout">Salir</button>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-4 py-5">
            <!-- Flash -->
            <p v-if="flash.success" class="mb-4 rounded-xl bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300 ring-1 ring-emerald-500/30">{{ flash.success }}</p>
            <p v-if="flash.error" class="mb-4 rounded-xl bg-red-500/15 px-4 py-3 text-sm text-red-300 ring-1 ring-red-500/30">{{ flash.error }}</p>

            <template v-if="hasRoute">
                <!-- Iniciar ruta: pasa los pedidos a «en camino» -->
                <button
                    class="mb-4 w-full rounded-xl py-3 text-sm font-semibold text-white"
                    style="background:#e30613"
                    @click="startRoute"
                >Iniciar ruta (marcar salida)</button>

                <!-- Resumen -->
                <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl border border-white/10 bg-neutral-900/60 p-3">
                        <p class="text-2xl font-bold">{{ summary.total }}</p>
                        <p class="text-xs text-neutral-400">Paradas</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-neutral-900/60 p-3">
                        <p class="text-2xl font-bold text-emerald-400">{{ summary.delivered }}</p>
                        <p class="text-xs text-neutral-400">Entregadas</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-neutral-900/60 p-3">
                        <p class="text-2xl font-bold text-amber-400">{{ summary.pending }}</p>
                        <p class="text-xs text-neutral-400">Pendientes</p>
                    </div>
                </div>

                <!-- Paradas -->
                <ul class="space-y-3">
                    <li
                        v-for="stop in stops" :key="stop.shipment_id"
                        class="rounded-2xl border border-white/10 bg-neutral-900/60 p-4"
                        :class="stop.done ? 'opacity-70' : ''"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                                :style="{ background: stop.done ? '#16a34a' : '#e30613' }"
                            >{{ stop.sequence }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate font-semibold">{{ stop.customer || '—' }}</p>
                                    <span class="ml-auto shrink-0 text-xs" :class="stop.done ? 'text-emerald-400' : 'text-amber-400'">
                                        {{ stop.done ? 'Entregada' : 'Pendiente' }}
                                    </span>
                                </div>
                                <p class="text-xs text-neutral-400">Pedido {{ stop.order || '—' }}</p>
                                <p class="mt-1 text-sm text-neutral-300">{{ stop.address || '—' }}</p>

                                <div class="mt-3 flex gap-2">
                                    <button
                                        class="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-neutral-200 hover:bg-white/5"
                                        @click="navigate(stop)"
                                    >Navegar</button>
                                    <button
                                        v-if="!stop.done"
                                        class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white"
                                        style="background:#16a34a"
                                        @click="confirmDeliver(stop)"
                                    >Confirmar entrega</button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>

            <!-- Sin ruta hoy -->
            <div v-else class="rounded-2xl border border-white/10 bg-neutral-900/60 p-6 text-center">
                <p class="text-sm text-neutral-300">No tenés una ruta asignada para hoy.</p>
                <p class="mt-1 text-xs text-neutral-500">Cuando la central publique tu ruta, aparecerá acá.</p>
            </div>
        </main>
    </div>
</template>
