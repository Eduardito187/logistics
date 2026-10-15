<script setup>
// Listado de rutas de reparto. Tabla reutilizable; clic en una fila → detalle (navegación Inertia, sin
// recargar). Patrón de referencia para listados de otros módulos.
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';

defineProps({
    routes: { type: Array, default: () => [] },
});

const columns = [
    { key: 'route_date', label: 'Fecha' },
    { key: 'vehicle', label: 'Vehículo' },
    { key: 'status', label: 'Estado' },
    { key: 'stops_count', label: 'Paradas', align: 'right' },
];

const statusMap = {
    PLANNED: { label: 'Planificada', variant: 'blue' },
    IN_TRANSIT: { label: 'En tránsito', variant: 'amber' },
    COMPLETED: { label: 'Completada', variant: 'green' },
    CANCELLED: { label: 'Cancelada', variant: 'red' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const openRoute = (row) => router.visit(route('app.routes.show', row.id));
</script>

<template>
    <Head title="Rutas" />

    <PageHeader title="Rutas de reparto" subtitle="Rutas del día y su avance. Haz clic en una ruta para ver el mapa." />

    <DataTable :columns="columns" :rows="routes" clickable empty-text="No hay rutas." @row-click="openRoute">
        <template #cell-status="{ value }">
            <StatusBadge :label="statusOf(value).label" :variant="statusOf(value).variant" />
        </template>
    </DataTable>
</template>
