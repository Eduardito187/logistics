<script setup>
// Tablero interactivo de un plan de reparto (fase 2). Sobre el detalle de solo lectura agrega:
//  - Reordenamiento de las paradas por ARRASTRE (HTML5 drag nativo: draggable + dragstart/dragover/drop);
//    al soltar, persiste la nueva secuencia con el comando ReorderStops (router.put).
//  - Acciones de escritura: publicar el plan (PublishPlan) y auto-planificar el día (GenerateDailyPlan).
// Las escrituras van por los controladores de la app (Presentation → Application → CommandBus); aquí solo
// se envían con Inertia y se refleja el resultado vía los mensajes flash. Reutiliza el mapa y las tarjetas
// de parada del detalle. Regla de negocio: solo se reordena DENTRO del plan (no se mezclan paradas entre
// camiones), por lo que el arrastre nunca saca una parada de su plan.
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';
import PlanStopCard from '@/Components/Planning/PlanStopCard.vue';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({
    plan: { type: Object, required: true },
    stops: { type: Array, default: () => [] },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [], geometry: [] }) },
});

const mapRef = ref(null);
const page = usePage();

// Copia local de las paradas: el arrastre reordena esta lista y, al soltar, se persiste. Se resincroniza
// cuando Inertia recarga las props (p.ej. tras guardar la secuencia o publicar).
const orderedStops = ref([...props.stops]);
watch(() => props.stops, (value) => {
    orderedStops.value = [...value];
});

const statusMap = {
    DRAFT: { label: 'Borrador', variant: 'gray' },
    PROPOSED: { label: 'Propuesto', variant: 'amber' },
    PUBLISHED: { label: 'Publicado', variant: 'green' },
};
const statusOf = (s) => statusMap[s] ?? { label: s, variant: 'gray' };

const flash = computed(() => page.props.flash ?? {});

// Estado del arrastre y de las peticiones en curso.
const dragIndex = ref(null);
const overIndex = ref(null);
const saving = ref(false);
const strategy = ref('optimization');

// Estrategias de auto-planificación disponibles para el dropdown.
const strategyOptions = [
    { value: 'optimization', label: 'Optimización (cercanía + capacidad)' },
    { value: 'slots', label: 'Franjas del cliente' },
];

const isPublished = computed(() => props.plan.status === 'PUBLISHED');
const canReorder = computed(() => !isPublished.value && orderedStops.value.length > 1);

const focusStop = (stop) => {
    if (stop.has_point) {
        mapRef.value?.focusStop(stop.seq);
    }
};

// --- Arrastre nativo (HTML5) para reordenar la secuencia ---

const onDragStart = (index) => {
    if (!canReorder.value) {
        return;
    }
    dragIndex.value = index;
};

const onDragOver = (index) => {
    if (dragIndex.value === null) {
        return;
    }
    overIndex.value = index;
};

const onDrop = (index) => {
    const from = dragIndex.value;
    dragIndex.value = null;
    overIndex.value = null;

    if (from === null || from === index) {
        return;
    }

    const list = [...orderedStops.value];
    const [moved] = list.splice(from, 1);
    list.splice(index, 0, moved);
    orderedStops.value = list;

    persistSequence();
};

const onDragEnd = () => {
    dragIndex.value = null;
    overIndex.value = null;
};

// Persiste el nuevo orden como la lista completa de envíos del plan (contrato del comando ReorderStops).
const persistSequence = () => {
    saving.value = true;
    router.put(
        route('app.planning.sequence', props.plan.id),
        { ordered_shipment_ids: orderedStops.value.map((s) => s.shipment_id) },
        {
            preserveScroll: true,
            onFinish: () => { saving.value = false; },
        },
    );
};

// --- Acciones de escritura ---

const publishing = ref(false);
const publishPlan = () => {
    if (publishing.value || isPublished.value) {
        return;
    }
    publishing.value = true;
    router.post(
        route('app.planning.publish', props.plan.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => { publishing.value = false; },
        },
    );
};

const autoPlanning = ref(false);
const autoPlan = () => {
    if (autoPlanning.value) {
        return;
    }
    autoPlanning.value = true;
    router.post(
        route('app.planning.auto'),
        { plan_date: props.plan.plan_date, strategy: strategy.value },
        {
            preserveScroll: true,
            onFinish: () => { autoPlanning.value = false; },
        },
    );
};
</script>

<template>
    <Head :title="`Tablero · Plan ${plan.plan_date}`" />

    <PageHeader :title="`Tablero · ${plan.plan_date}`" :subtitle="`Vehículo ${plan.vehicle} · ${plan.stops_count} paradas · arrastra las paradas para reordenar`">
        <template #actions>
            <StatusBadge :label="statusOf(plan.status).label" :variant="statusOf(plan.status).variant" />
            <Link :href="route('app.planning.show', plan.id)" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                Ver detalle
            </Link>
            <Link :href="route('app.planning.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <!-- Mensajes flash de las escrituras (comparte HandleInertiaRequests) -->
    <div v-if="flash.success" class="mb-4 rounded-lg border border-green-700/50 bg-green-900/30 px-4 py-2 text-sm text-green-300">
        {{ flash.success }}
    </div>
    <div v-if="flash.error" class="mb-4 rounded-lg border border-red-700/50 bg-red-900/30 px-4 py-2 text-sm text-red-300">
        {{ flash.error }}
    </div>

    <!-- Barra de acciones de escritura -->
    <AppCard class="mb-5">
        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                :disabled="publishing || isPublished"
                class="rounded-lg bg-[#E2231A] px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-[#c41d15] disabled:cursor-not-allowed disabled:opacity-40"
                @click="publishPlan"
            >
                {{ isPublished ? 'Plan publicado' : (publishing ? 'Publicando…' : 'Publicar plan') }}
            </button>

            <div class="flex items-center gap-2">
                <label class="text-xs text-neutral-500">Estrategia</label>
                <div class="w-64">
                    <Select v-model="strategy" :options="strategyOptions" placeholder="Selecciona una estrategia…" />
                </div>
                <button
                    type="button"
                    :disabled="autoPlanning"
                    class="rounded-lg border border-neutral-700 px-4 py-1.5 text-sm font-semibold text-neutral-200 transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-40"
                    @click="autoPlan"
                >
                    {{ autoPlanning ? 'Encolando…' : 'Auto-planificar el día' }}
                </button>
            </div>

            <span v-if="saving" class="text-xs text-neutral-400">Guardando secuencia…</span>
        </div>
    </AppCard>

    <AppCard title="Mapa del plan" class="mb-5">
        <RouteMap ref="mapRef" :stops="map.stops" :planned="map.planned" :actual="map.actual" :geometry="map.geometry" :osrm-host="map.osrmHost" />
        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-neutral-400">
            <span class="inline-flex items-center gap-2">
                <span class="inline-block w-5" style="border-top:3px dashed #2563eb"></span> Ruta planificada
            </span>
        </div>
    </AppCard>

    <h2 class="mb-3 text-sm font-semibold text-neutral-300">
        Paradas en orden de entrega
        <span v-if="!canReorder && isPublished" class="ml-2 text-xs font-normal text-neutral-500">(plan publicado: solo lectura)</span>
        <span v-else class="ml-2 text-xs font-normal text-neutral-500">(arrastra una tarjeta para cambiar su posición)</span>
    </h2>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="(stop, index) in orderedStops"
            :key="stop.shipment_id"
            :draggable="canReorder"
            class="transition"
            :class="[
                canReorder ? 'cursor-grab active:cursor-grabbing' : '',
                dragIndex === index ? 'opacity-40' : '',
                overIndex === index && dragIndex !== null && dragIndex !== index ? 'ring-2 ring-[#E2231A] rounded-xl' : '',
            ]"
            @dragstart="onDragStart(index)"
            @dragover.prevent="onDragOver(index)"
            @drop.prevent="onDrop(index)"
            @dragend="onDragEnd"
        >
            <PlanStopCard :stop="stop" @focus="focusStop" />
        </div>
    </div>
</template>
