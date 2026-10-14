<script setup>
// Índice del módulo Personal: dos tablas, choferes y ayudantes. Clic en una fila → perfil (navegación
// Inertia, sin recargar). Solo lectura. Valores legibles, nunca UUIDs.
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';

defineProps({
    drivers: { type: Array, default: () => [] },
    helpers: { type: Array, default: () => [] },
});

const driverColumns = [
    { key: 'name', label: 'Nombre' },
    { key: 'license_number', label: 'Placa de licencia' },
    { key: 'license_category', label: 'Categoría' },
    { key: 'age', label: 'Edad', align: 'right' },
    { key: 'active', label: 'Estado' },
];

const helperColumns = [
    { key: 'name', label: 'Nombre' },
    { key: 'phone', label: 'Teléfono' },
    { key: 'active', label: 'Estado' },
];

// Estado activo/inactivo a badge legible.
const activeBadge = (active) =>
    active ? { label: 'Activo', variant: 'green' } : { label: 'Inactivo', variant: 'gray' };

const openDriver = (row) => router.visit(route('app.personnel.drivers.show', row.id));
const openHelper = (row) => router.visit(route('app.personnel.helpers.show', row.id));
</script>

<template>
    <Head title="Personal" />

    <PageHeader title="Personal" subtitle="Choferes y ayudantes de la flota. Haz clic en una persona para ver su perfil." />

    <AppCard title="Choferes" class="mb-6">
        <DataTable :columns="driverColumns" :rows="drivers" clickable empty-text="No hay choferes." @row-click="openDriver">
            <template #cell-age="{ value }">
                {{ value != null ? `${value} años` : '—' }}
            </template>
            <template #cell-active="{ value }">
                <StatusBadge :label="activeBadge(value).label" :variant="activeBadge(value).variant" />
            </template>
        </DataTable>
    </AppCard>

    <AppCard title="Ayudantes">
        <DataTable :columns="helperColumns" :rows="helpers" clickable empty-text="No hay ayudantes." @row-click="openHelper">
            <template #cell-active="{ value }">
                <StatusBadge :label="activeBadge(value).label" :variant="activeBadge(value).variant" />
            </template>
        </DataTable>
    </AppCard>
</template>
