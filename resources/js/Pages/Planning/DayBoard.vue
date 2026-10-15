<script setup>
// Tablero del DÍA de Planificación: una columna por camión (plan) de una fecha, con sus paradas. A
// diferencia del tablero de un plan (Board.vue, que reordena DENTRO del plan), aquí se arrastra una
// parada de una columna a OTRA para MOVER el envío a otro camión. El arrastre usa HTML5 nativo (sin
// librerías): cada tarjeta es draggable y cada columna es zona de drop. Al soltar en otra columna, se
// persiste con el comando ReassignShipmentToVehicle (router.post). El backend valida las invariantes
// (mismo departamento de las paradas de cliente; capacidad del vehículo de destino) y responde con flash
// success/error (el AppLayout ya muestra el flash global). Reutiliza PageHeader, AppCard, StatusBadge y
// PlanStopCard.
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import PlanStopCard from '@/Components/Planning/PlanStopCard.vue';

const props = defineProps({
    date: { type: String, required: true },
    columns: { type: Array, default: () => [] },
});

// Copia local de la fecha seleccionada en el selector (navega a otro día al cambiar).
const selectedDate = ref(props.date);
watch(() => props.date, (value) => { selectedDate.value = value; });

const statusMap = {
    DRAFT: { label: 'Borrador', variant: 'gray' },
    PROPOSED: { label: 'Propuesto', variant: 'amber' },
    PUBLISHED: { label: 'Publicado', variant: 'green' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

// Un plan publicado tiene su hoja de ruta congelada: ni suelta ni recibe paradas (el dominio lo rechaza).
const isLocked = (column) => column.status === 'PUBLISHED';

// --- Arrastre nativo (HTML5) entre columnas ---

// Parada que se está arrastrando: de qué plan sale y qué envío es.
const dragging = ref(null);
const overPlanId = ref(null);
const moving = ref(false);

const onDragStart = (column, stop) => {
    if (isLocked(column)) {
        return;
    }
    dragging.value = { fromPlanId: column.id, shipmentId: stop.shipment_id };
};

const onDragEnd = () => {
    dragging.value = null;
    overPlanId.value = null;
};

const onDragOver = (column) => {
    if (dragging.value === null || isLocked(column)) {
        return;
    }
    overPlanId.value = column.id;
};

const onDrop = (column) => {
    const payload = dragging.value;
    overPlanId.value = null;
    dragging.value = null;

    // Nada que hacer si no hay arrastre, si la columna está bloqueada o si se suelta en el mismo camión.
    if (payload === null || isLocked(column) || payload.fromPlanId === column.id || moving.value) {
        return;
    }

    moving.value = true;
    router.post(
        route('app.planning.reassign'),
        {
            shipment_id: payload.shipmentId,
            from_plan_id: payload.fromPlanId,
            to_plan_id: column.id,
        },
        {
            preserveScroll: true,
            onFinish: () => { moving.value = false; },
        },
    );
};

// Columna resaltada: la que recibe el arrastre (y no es el origen ni está bloqueada).
const isDropTarget = (column) =>
    dragging.value !== null
    && overPlanId.value === column.id
    && dragging.value.fromPlanId !== column.id
    && !isLocked(column);

// Navega al tablero de otra fecha.
const goToDate = () => {
    if (selectedDate.value) {
        router.visit(route('app.planning.day', selectedDate.value));
    }
};

const hasColumns = computed(() => props.columns.length > 0);
</script>

<template>
    <Head :title="`Tablero del día · ${date}`" />

    <PageHeader
        title="Tablero del día"
        :subtitle="`Planes del ${date}. Arrastra una parada de un camión a otro para mover el envío.`"
    >
        <template #actions>
            <input
                v-model="selectedDate"
                type="date"
                class="rounded-lg border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-sm text-neutral-200"
                @change="goToDate"
            >
            <Link
                :href="route('app.planning.index')"
                class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800"
            >
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <p v-if="moving" class="mb-4 text-xs text-neutral-400">Moviendo el envío…</p>

    <div v-if="!hasColumns" class="rounded-xl border border-neutral-800 bg-neutral-900/40 px-4 py-10 text-center text-sm text-neutral-500">
        No hay planes para esta fecha.
    </div>

    <!-- Kanban: una columna por camión, con scroll horizontal cuando hay muchos. -->
    <div v-else class="flex gap-4 overflow-x-auto pb-4">
        <div
            v-for="column in columns"
            :key="column.id"
            class="w-80 shrink-0 rounded-xl border p-3 transition"
            :class="[
                isDropTarget(column) ? 'border-[#E2231A] bg-[#E2231A]/5' : 'border-neutral-800 bg-neutral-900/40',
                isLocked(column) ? 'opacity-70' : '',
            ]"
            @dragover.prevent="onDragOver(column)"
            @drop.prevent="onDrop(column)"
        >
            <!-- Cabecera de la columna: camión, estado y cupo -->
            <div class="mb-3 flex items-start justify-between gap-2">
                <div>
                    <Link
                        :href="route('app.planning.board', column.id)"
                        class="text-sm font-semibold text-neutral-100 transition hover:text-[#ff8a80]"
                    >
                        {{ column.vehicle }}
                    </Link>
                    <p class="mt-0.5 text-xs text-neutral-500">
                        {{ column.stops_count }}
                        <span v-if="column.capacity">/ {{ column.capacity }}</span>
                        paradas
                    </p>
                </div>
                <StatusBadge :label="statusOf(column.status).label" :variant="statusOf(column.status).variant" />
            </div>

            <p v-if="isLocked(column)" class="mb-2 text-xs text-neutral-600">
                Plan publicado: no admite cambios.
            </p>

            <!-- Paradas arrastrables -->
            <div class="space-y-3">
                <div
                    v-for="stop in column.stops"
                    :key="stop.shipment_id"
                    :draggable="!isLocked(column)"
                    class="transition"
                    :class="[
                        !isLocked(column) ? 'cursor-grab active:cursor-grabbing' : '',
                        dragging && dragging.shipmentId === stop.shipment_id ? 'opacity-40' : '',
                    ]"
                    @dragstart="onDragStart(column, stop)"
                    @dragend="onDragEnd"
                >
                    <PlanStopCard :stop="stop" />
                </div>

                <p v-if="column.stops.length === 0" class="rounded-lg border border-dashed border-neutral-800 px-3 py-6 text-center text-xs text-neutral-600">
                    Sin paradas. Arrastra un envío aquí.
                </p>
            </div>
        </div>
    </div>
</template>
