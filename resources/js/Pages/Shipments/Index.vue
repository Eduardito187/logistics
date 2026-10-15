<script setup>
// Listado de pedidos recibidos (vista operativa, solo lectura). Tabla reutilizable; clic en una fila →
// detalle del pedido (navegación Inertia). Mismo patrón que el listado de rutas del piloto.
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ConfidenceBadge from '@/Components/Shipments/ConfidenceBadge.vue';

defineProps({
    shipments: { type: Array, default: () => [] },
});

const columns = [
    { key: 'external_id', label: 'Pedido' },
    { key: 'source', label: 'Origen' },
    { key: 'customer', label: 'Cliente' },
    { key: 'status', label: 'Estado' },
    { key: 'confidence', label: 'Dirección', align: 'right' },
];

// Etiquetas legibles del origen del pedido (enum OrderSource del dominio).
const sourceMap = {
    SAP: 'SAP',
    MAGENTO: 'Magento',
    STORE: 'Tienda',
    MANUAL: 'Manual',
    EXCEL: 'Excel',
};
const sourceOf = (s) => sourceMap[s] ?? s;

// Estados del ciclo de vida del pedido (enum ShipmentStatus) a etiqueta + color.
const statusMap = {
    RECEIVED: { label: 'Recibido', variant: 'gray' },
    PLANNED: { label: 'Planificado', variant: 'blue' },
    IN_EXECUTION: { label: 'En ejecución', variant: 'amber' },
    DELIVERED: { label: 'Entregado', variant: 'green' },
    FAILED: { label: 'Fallido', variant: 'red' },
    CANCELLED: { label: 'Cancelado', variant: 'red' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const openShipment = (row) => router.visit(route('app.shipments.show', row.id));
</script>

<template>
    <Head title="Pedidos" />

    <PageHeader title="Pedidos" subtitle="Pedidos recibidos para reparto. Haz clic en un pedido para ver su detalle." />

    <DataTable :columns="columns" :rows="shipments" clickable empty-text="No hay pedidos." @row-click="openShipment">
        <template #cell-source="{ value }">
            {{ sourceOf(value) }}
        </template>
        <template #cell-status="{ value }">
            <StatusBadge :label="statusOf(value).label" :variant="statusOf(value).variant" />
        </template>
        <template #cell-confidence="{ row }">
            <ConfidenceBadge :score="row.confidence" :level="row.confidence_level" />
        </template>
    </DataTable>
</template>
