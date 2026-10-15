<script setup>
// Listado de planes de reparto (tablero de planificación). Tabla reutilizable; clic en una fila →
// detalle del plan (navegación Inertia, sin recargar). Solo lectura: la generación y publicación del
// plan siguen en el admin. Mismo patrón que el listado de Rutas del piloto.
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';

defineProps({
    plans: { type: Array, default: () => [] },
});

const columns = [
    { key: 'plan_date', label: 'Fecha' },
    { key: 'vehicle', label: 'Vehículo' },
    { key: 'status', label: 'Estado' },
    { key: 'stops_count', label: 'Paradas', align: 'right' },
];

// Estados del plan (enum PlanStatus del contexto Planning).
const statusMap = {
    DRAFT: { label: 'Borrador', variant: 'gray' },
    PROPOSED: { label: 'Propuesto', variant: 'amber' },
    PUBLISHED: { label: 'Publicado', variant: 'green' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const openPlan = (row) => router.visit(route('app.planning.show', row.id));

// Fecha de hoy ('Y-m-d') para abrir el tablero del día (arrastrar pedidos entre camiones).
const today = new Date().toLocaleDateString('en-CA');
</script>

<template>
    <Head title="Planificación" />

    <PageHeader title="Planificación" subtitle="Planes de reparto por camión. Haz clic en un plan para ver sus paradas y el mapa.">
        <template #actions>
            <Link
                :href="route('app.planning.day', today)"
                class="rounded-lg bg-[#E2231A] px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-[#c41d15]"
            >
                Tablero del día
            </Link>
        </template>
    </PageHeader>

    <DataTable :columns="columns" :rows="plans" clickable empty-text="No hay planes." @row-click="openPlan">
        <template #cell-status="{ value }">
            <StatusBadge :label="statusOf(value).label" :variant="statusOf(value).variant" />
        </template>
    </DataTable>
</template>
