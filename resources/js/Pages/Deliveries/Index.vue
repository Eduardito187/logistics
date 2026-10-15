<script setup>
// Listado de entregas en ejecución. Tabla reutilizable; clic en una fila → detalle (navegación Inertia,
// sin recargar). Mismo patrón que el listado de Rutas del piloto.
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { deliveryStatus } from '@/Components/Deliveries/status.js';

defineProps({
    deliveries: { type: Array, default: () => [] },
});

const columns = [
    { key: 'order', label: 'Pedido' },
    { key: 'customer', label: 'Cliente' },
    { key: 'vehicle', label: 'Vehículo' },
    { key: 'status', label: 'Estado' },
    { key: 'pod_captured_at', label: 'Fecha de entrega' },
];

const openDelivery = (row) => router.visit(route('app.deliveries.show', row.id));
</script>

<template>
    <Head title="Entregas" />

    <PageHeader
        title="Entregas"
        subtitle="Entregas en ejecución y su estado. Haz clic en una entrega para ver la prueba de entrega y sus escaneos."
    />

    <DataTable :columns="columns" :rows="deliveries" clickable empty-text="No hay entregas." @row-click="openDelivery">
        <template #cell-status="{ value }">
            <StatusBadge :label="deliveryStatus(value).label" :variant="deliveryStatus(value).variant" />
        </template>
        <template #cell-pod_captured_at="{ value }">
            {{ value ?? '—' }}
        </template>
    </DataTable>
</template>
