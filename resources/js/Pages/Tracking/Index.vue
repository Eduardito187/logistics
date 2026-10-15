<script setup>
// Listado de seguimientos de envíos. Tabla reutilizable; clic en una fila → detalle (navegación Inertia,
// sin recargar). Valores legibles: pedido (external_id), cliente, estado visible, ETA y paradas
// restantes. Nunca UUIDs crudos ni el token público.
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import TrackingStatusBadge from '@/Components/Tracking/TrackingStatusBadge.vue';

defineProps({
    trackings: { type: Array, default: () => [] },
});

const columns = [
    { key: 'order', label: 'Pedido' },
    { key: 'customer', label: 'Cliente' },
    { key: 'status', label: 'Estado' },
    { key: 'eta', label: 'ETA' },
    { key: 'remaining_stops', label: 'Paradas restantes', align: 'right' },
];

const openTracking = (row) => router.visit(route('app.tracking.show', row.id));
</script>

<template>
    <Head title="Seguimiento" />

    <PageHeader title="Seguimiento de envíos" subtitle="Estado visible de cada envío y su avance hacia el cliente. Haz clic en un envío para ver el detalle." />

    <DataTable :columns="columns" :rows="trackings" clickable empty-text="No hay seguimientos." @row-click="openTracking">
        <template #cell-status="{ value }">
            <TrackingStatusBadge :status="value" />
        </template>
        <template #cell-eta="{ value }">
            <span>{{ value ?? '—' }}</span>
        </template>
    </DataTable>
</template>
