<script setup>
// Detalle de un pedido (solo lectura): cabecera legible, datos de cliente/dirección/ventana de entrega,
// mapa de la dirección (RouteMap con una sola parada, si hay coordenadas), tabla de líneas de producto y
// bultos del pedido. Resuelve valores legibles, nunca UUIDs. Mismo patrón que el detalle de rutas.
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';
import ConfidenceBadge from '@/Components/Shipments/ConfidenceBadge.vue';

const props = defineProps({
    shipment: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    map: { type: Object, default: null },
});

const sourceMap = {
    SAP: 'SAP',
    MAGENTO: 'Magento',
    STORE: 'Tienda',
    MANUAL: 'Manual',
    EXCEL: 'Excel',
};
const sourceOf = (s) => sourceMap[s] ?? s;

const statusMap = {
    RECEIVED: { label: 'Recibido', variant: 'gray' },
    PLANNED: { label: 'Planificado', variant: 'blue' },
    IN_EXECUTION: { label: 'En ejecución', variant: 'amber' },
    DELIVERED: { label: 'Entregado', variant: 'green' },
    FAILED: { label: 'Fallido', variant: 'red' },
    CANCELLED: { label: 'Cancelado', variant: 'red' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const lineColumns = [
    { key: 'product_name', label: 'Producto' },
    { key: 'sku', label: 'SKU' },
    { key: 'quantity', label: 'Cantidad', align: 'right' },
    { key: 'unit_price', label: 'Precio unit.', align: 'right' },
];

// Formatea el precio unitario con su moneda; '—' si no hay precio.
const money = (value, currency) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const number = Number(value);
    const amount = Number.isNaN(number) ? value : number.toFixed(2);
    return `${currency ?? 'BOB'} ${amount}`;
};

// Ventana de entrega legible (inicio–fin) si existe.
const deliveryWindow = () => {
    const start = props.shipment.delivery_window_start;
    const end = props.shipment.delivery_window_end;
    return start && end ? `${start}–${end}` : null;
};
</script>

<template>
    <Head :title="`Pedido ${shipment.external_id}`" />

    <PageHeader :title="`Pedido · ${shipment.external_id}`" :subtitle="`Origen ${sourceOf(shipment.source)}`">
        <template #actions>
            <StatusBadge :label="statusOf(shipment.status).label" :variant="statusOf(shipment.status).variant" />
            <Link :href="route('app.shipments.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <AppCard title="Cliente">
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Nombre</dt>
                    <dd class="text-neutral-200">{{ shipment.customer }}</dd>
                </div>
                <div v-if="shipment.customer_document_id">
                    <dt class="text-xs text-neutral-500">Documento</dt>
                    <dd class="text-neutral-300">{{ shipment.customer_document_id }}</dd>
                </div>
                <div v-if="shipment.customer_phone">
                    <dt class="text-xs text-neutral-500">Teléfono</dt>
                    <dd class="text-neutral-300">{{ shipment.customer_phone }}</dd>
                </div>
                <div v-if="shipment.customer_email">
                    <dt class="text-xs text-neutral-500">Correo</dt>
                    <dd class="text-neutral-300">{{ shipment.customer_email }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard title="Dirección de entrega">
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Dirección</dt>
                    <dd class="text-neutral-200">{{ shipment.address_text }}</dd>
                </div>
                <div v-if="shipment.address_reference">
                    <dt class="text-xs text-neutral-500">Referencia</dt>
                    <dd class="text-neutral-300">{{ shipment.address_reference }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Confianza de la dirección</dt>
                    <dd class="mt-1">
                        <ConfidenceBadge :score="shipment.confidence" :level="shipment.confidence_level" />
                    </dd>
                </div>
                <div v-if="shipment.delivery_date">
                    <dt class="text-xs text-neutral-500">Entrega</dt>
                    <dd class="text-neutral-300">
                        {{ shipment.delivery_date }}<template v-if="deliveryWindow()"> · {{ deliveryWindow() }}</template>
                    </dd>
                </div>
            </dl>
        </AppCard>
    </div>

    <AppCard v-if="map" title="Ubicación de entrega" class="mt-5">
        <RouteMap :stops="map.stops" :planned="map.planned" :actual="map.actual" />
    </AppCard>

    <h2 class="mb-3 mt-6 text-sm font-semibold text-neutral-300">Líneas de producto</h2>
    <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty-text="Este pedido no tiene líneas.">
        <template #cell-product_name="{ row }">
            <div class="flex items-center gap-3">
                <img
                    v-if="row.image"
                    :src="row.image"
                    :alt="row.product_name"
                    class="h-10 w-10 shrink-0 rounded-md object-cover"
                    loading="lazy"
                />
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-neutral-800 text-xs text-neutral-500" v-else>—</span>
                <span class="text-neutral-200">{{ row.product_name }}</span>
            </div>
        </template>
        <template #cell-unit_price="{ row }">
            {{ money(row.unit_price, row.currency) }}
        </template>
    </DataTable>

    <h2 class="mb-3 mt-6 text-sm font-semibold text-neutral-300">Bultos</h2>
    <AppCard v-if="units.length === 0">
        <p class="text-sm text-neutral-500">Este pedido no tiene bultos registrados.</p>
    </AppCard>
    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <AppCard v-for="unit in units" :key="unit.id">
            <div class="flex items-start justify-between">
                <span class="text-sm font-semibold text-neutral-200">
                    Bulto {{ unit.unit_index }}/{{ unit.unit_total }}
                </span>
                <span class="text-xs text-neutral-500">Pieza {{ unit.piece_index }}/{{ unit.piece_total }}</span>
            </div>
            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Producto</dt>
                    <dd class="text-neutral-200">{{ unit.product_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">SKU</dt>
                    <dd class="text-neutral-300">{{ unit.sku }}</dd>
                </div>
            </dl>
        </AppCard>
    </div>
</template>
