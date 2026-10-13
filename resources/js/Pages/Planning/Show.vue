<script setup>
// Detalle de un plan de reparto: cabecera, mapa (RouteMap reutilizado con las paradas geolocalizadas y
// el trazado planificado) y las paradas en orden como tarjetas clickeables. Clic en una tarjeta con
// ubicación centra y abre su marcador en el mapa vía la ref del componente de mapa. Solo lectura; el
// "arrastrar pedidos entre camiones" queda para una fase 2. Mismo patrón que el detalle de Rutas.
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';
import PlanStopCard from '@/Components/Planning/PlanStopCard.vue';

const props = defineProps({
    plan: { type: Object, required: true },
    stops: { type: Array, default: () => [] },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [] }) },
});

const mapRef = ref(null);

const statusMap = {
    DRAFT: { label: 'Borrador', variant: 'gray' },
    PROPOSED: { label: 'Propuesto', variant: 'amber' },
    PUBLISHED: { label: 'Publicado', variant: 'green' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const focusStop = (stop) => {
    if (stop.has_point) {
        mapRef.value?.focusStop(stop.seq);
    }
};
</script>

<template>
    <Head :title="`Plan ${plan.plan_date}`" />

    <PageHeader :title="`Plan · ${plan.plan_date}`" :subtitle="`Vehículo ${plan.vehicle} · ${plan.stops_count} paradas`">
        <template #actions>
            <StatusBadge :label="statusOf(plan.status).label" :variant="statusOf(plan.status).variant" />
            <Link :href="route('app.planning.board', plan.id)" class="rounded-lg border border-[#E2231A]/60 px-3 py-1.5 text-sm font-semibold text-[#ff8a80] transition hover:bg-[#E2231A]/10">
                Abrir tablero
            </Link>
            <Link :href="route('app.planning.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <AppCard title="Mapa del plan" class="mb-5">
        <RouteMap ref="mapRef" :stops="map.stops" :planned="map.planned" :actual="map.actual" />
        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-neutral-400">
            <span class="inline-flex items-center gap-2">
                <span class="inline-block w-5" style="border-top:3px dashed #2563eb"></span> Ruta planificada
            </span>
        </div>
    </AppCard>

    <h2 class="mb-3 text-sm font-semibold text-neutral-300">Paradas en orden de entrega</h2>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <PlanStopCard
            v-for="stop in stops"
            :key="stop.seq"
            :stop="stop"
            @focus="focusStop"
        />
    </div>
</template>
