<script setup>
// Detalle de una ruta: cabecera, mapa en vivo (RouteMap) y paradas en tarjetas. Clic en TODA la tarjeta
// de una parada (si tiene ubicación) centra y abre su marcador en el mapa — vía la ref del componente de
// mapa, sin eventos globales. Patrón de referencia para pantallas con mapa + lista interactiva.
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';

const props = defineProps({
    route: { type: Object, required: true },
    stops: { type: Array, default: () => [] },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [] }) },
});

const mapRef = ref(null);

const routeStatusMap = {
    PLANNED: { label: 'Planificada', variant: 'blue' },
    IN_TRANSIT: { label: 'En tránsito', variant: 'amber' },
    COMPLETED: { label: 'Completada', variant: 'green' },
    CANCELLED: { label: 'Cancelada', variant: 'red' },
};
const stopStatusMap = {
    PENDING: { label: 'Pendiente', variant: 'gray' },
    DELIVERED: { label: 'Entregado', variant: 'green' },
    FAILED: { label: 'No aprobado', variant: 'red' },
    IN_TRANSIT: { label: 'En tránsito', variant: 'amber' },
};
const routeStatus = (s) => routeStatusMap[s] ?? { label: s, variant: 'gray' };
const stopStatus = (s) => stopStatusMap[s] ?? { label: s, variant: 'gray' };

const focusStop = (stop) => {
    if (stop.has_point) {
        mapRef.value?.focusStop(stop.seq);
    }
};
</script>

<template>
    <Head :title="`Ruta ${route.route_date}`" />

    <PageHeader :title="`Ruta · ${route.route_date}`" :subtitle="`Vehículo ${route.vehicle}`">
        <template #actions>
            <StatusBadge :label="routeStatus(route.status).label" :variant="routeStatus(route.status).variant" />
            <Link :href="route('app.routes.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <AppCard title="Mapa de la ruta" class="mb-5">
        <RouteMap ref="mapRef" :stops="map.stops" :planned="map.planned" :actual="map.actual" />
        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-neutral-400">
            <span class="inline-flex items-center gap-2">
                <span class="inline-block w-5" style="border-top:3px dashed #2563eb"></span> Ruta planificada
            </span>
            <span class="inline-flex items-center gap-2">
                <span class="inline-block w-5" style="border-top:3px solid #E2231A"></span> Recorrido real (GPS)
            </span>
        </div>
    </AppCard>

    <h2 class="mb-3 text-sm font-semibold text-neutral-300">Paradas en orden de entrega</h2>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <AppCard
            v-for="stop in stops"
            :key="stop.seq"
            :class="stop.has_point ? 'cursor-pointer transition hover:border-[#E2231A]/40' : ''"
            :role="stop.has_point ? 'button' : null"
            @click="focusStop(stop)"
        >
            <div class="flex items-start justify-between">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#E2231A]/20 text-xs font-bold text-[#ff8a80]">
                    {{ stop.seq }}
                </span>
                <StatusBadge :label="stopStatus(stop.status).label" :variant="stopStatus(stop.status).variant" />
            </div>
            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Pedido</dt>
                    <dd class="text-neutral-200">{{ stop.order }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Cliente</dt>
                    <dd class="text-neutral-200">{{ stop.customer }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Dirección</dt>
                    <dd class="text-neutral-300">{{ stop.address }}</dd>
                </div>
                <div v-if="stop.eta">
                    <dt class="text-xs text-neutral-500">ETA</dt>
                    <dd class="text-neutral-300">{{ stop.eta }}</dd>
                </div>
            </dl>
            <p v-if="stop.has_point" class="mt-3 text-xs text-[#ff8a80]">Clic para ver en el mapa →</p>
            <p v-else class="mt-3 text-xs text-neutral-600">Sin ubicación en el mapa</p>
        </AppCard>
    </div>
</template>
