<script setup>
// Desempeño operativo del chofer en un rango: tarjetas con los totales y una tabla con el desglose
// día por día (camión, ayudantes, entregadas, fallidas, reprogramadas). Los números vienen del lado de
// lectura CQRS (GetDriverHistory → DriverHistoryView). Solo lectura.
import { computed } from 'vue';
import AppCard from '@/Components/UI/AppCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';

const props = defineProps({
    // Estructura DriverHistoryView->toArray(): { totals: {...}, days: [...] }.
    performance: { type: Object, required: true },
    // Mapa motivo de fallo → etiqueta legible.
    reasonLabels: { type: Object, default: () => ({}) },
});

const totals = computed(() => props.performance.totals ?? {});
const days = computed(() => props.performance.days ?? []);

const stats = computed(() => [
    { label: 'Días trabajados', value: totals.value.days_worked ?? 0 },
    { label: 'Paradas totales', value: totals.value.total_stops ?? 0 },
    { label: 'Entregadas', value: totals.value.delivered ?? 0 },
    { label: 'Fallidas', value: totals.value.failed ?? 0 },
    { label: 'Reprogramadas', value: totals.value.rescheduled ?? 0 },
]);

// Motivos de fallo agregados del rango, con etiqueta legible.
const failedByReason = computed(() =>
    Object.entries(totals.value.failed_by_reason ?? {}).map(([reason, count]) => ({
        label: props.reasonLabels[reason] ?? reason,
        count,
    })),
);

const vehicles = computed(() => totals.value.vehicles ?? []);

const dayColumns = [
    { key: 'date', label: 'Fecha' },
    { key: 'vehicle_plate', label: 'Camión' },
    { key: 'helper_names', label: 'Ayudantes' },
    { key: 'delivered', label: 'Entregadas', align: 'right' },
    { key: 'failed', label: 'Fallidas', align: 'right' },
    { key: 'rescheduled', label: 'Reprogramadas', align: 'right' },
];
</script>

<template>
    <div>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div v-for="stat in stats" :key="stat.label" class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-4">
                <p class="text-xs text-neutral-500">{{ stat.label }}</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-100">{{ stat.value }}</p>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <AppCard title="Camiones manejados">
                <p v-if="vehicles.length === 0" class="text-sm text-neutral-500">Ninguno en el rango.</p>
                <div v-else class="flex flex-wrap gap-2">
                    <StatusBadge v-for="plate in vehicles" :key="plate" :label="plate" variant="blue" />
                </div>
            </AppCard>

            <AppCard title="Fallas por motivo">
                <p v-if="failedByReason.length === 0" class="text-sm text-neutral-500">Sin fallas en el rango.</p>
                <ul v-else class="space-y-1.5">
                    <li v-for="item in failedByReason" :key="item.label" class="flex items-center justify-between text-sm">
                        <span class="text-neutral-300">{{ item.label }}</span>
                        <StatusBadge :label="String(item.count)" variant="red" />
                    </li>
                </ul>
            </AppCard>
        </div>

        <h3 class="mb-3 text-sm font-semibold text-neutral-300">Desglose por día</h3>
        <DataTable :columns="dayColumns" :rows="days" row-key="date" empty-text="Sin actividad en el rango.">
            <template #cell-helper_names="{ value }">
                {{ value && value.length ? value.join(', ') : '—' }}
            </template>
        </DataTable>
    </div>
</template>
