<script setup>
// Torre de control en vivo: mapa con la última posición conocida de cada vehículo de la flota y una
// tabla lateral con su estado, entregas activas y última actualización. Clic en una fila con ubicación
// centra el vehículo en el mapa (vía la ref de RouteMap). Solo lectura. Patrón: igual que Rutas.
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';

const props = defineProps({
    fleet: { type: Array, default: () => [] },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [] }) },
    summary: { type: Object, default: () => ({ vehicles: 0, on_map: 0, active_deliveries: 0 }) },
});

const mapRef = ref(null);

// Estado del vehículo → etiqueta en español + color del badge. El valor crudo llega en minúsculas.
const vehicleStatusMap = {
    available: { label: 'Disponible', variant: 'green' },
    in_service: { label: 'En servicio', variant: 'blue' },
    maintenance: { label: 'Mantenimiento', variant: 'amber' },
    inactive: { label: 'Inactivo', variant: 'gray' },
};
const vehicleStatus = (s) => vehicleStatusMap[s] ?? { label: s, variant: 'gray' };

const columns = [
    { key: 'plate', label: 'Placa' },
    { key: 'status', label: 'Estado' },
    { key: 'active_deliveries', label: 'Entregas activas', align: 'right' },
    { key: 'last_update', label: 'Última actualización' },
];

// Formatea la fecha ISO de la última posición a hora local legible; '—' si no hay dato.
const formatUpdate = (value) => {
    if (!value || value === '—') {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
};

// Clic en una fila con ubicación: centra ese vehículo en el mapa.
const focusVehicle = (row) => {
    const stop = props.map.stops.find((s) => s.order === row.plate);
    if (stop) {
        mapRef.value?.focusStop(stop.seq);
    }
};
</script>

<template>
    <Head title="Torre de control" />

    <PageHeader
        title="Torre de control"
        subtitle="Flota en vivo: última posición conocida, estado y entregas activas de cada vehículo."
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2 text-xs text-neutral-400">
                <span class="rounded-lg border border-neutral-700 px-3 py-1.5">
                    Vehículos: <strong class="text-neutral-200">{{ summary.vehicles }}</strong>
                </span>
                <span class="rounded-lg border border-neutral-700 px-3 py-1.5">
                    En mapa: <strong class="text-neutral-200">{{ summary.on_map }}</strong>
                </span>
                <span class="rounded-lg border border-neutral-700 px-3 py-1.5">
                    Entregas activas: <strong class="text-neutral-200">{{ summary.active_deliveries }}</strong>
                </span>
            </div>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-5">
        <AppCard title="Mapa de la flota" class="xl:col-span-3">
            <RouteMap ref="mapRef" :stops="map.stops" :planned="map.planned" :actual="map.actual" />
            <p class="mt-3 text-xs text-neutral-500">
                Cada marcador es un vehículo en su última posición reportada. Haz clic en una fila de la
                tabla para centrarlo.
            </p>
        </AppCard>

        <AppCard title="Flota" class="xl:col-span-2">
            <DataTable
                :columns="columns"
                :rows="fleet"
                clickable
                empty-text="No hay vehículos en la flota."
                @row-click="focusVehicle"
            >
                <template #cell-status="{ value }">
                    <StatusBadge :label="vehicleStatus(value).label" :variant="vehicleStatus(value).variant" />
                </template>
                <template #cell-last_update="{ value }">
                    {{ formatUpdate(value) }}
                </template>
            </DataTable>
        </AppCard>
    </div>
</template>
