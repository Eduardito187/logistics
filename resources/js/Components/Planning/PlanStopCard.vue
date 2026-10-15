<script setup>
// Tarjeta de una parada planificada (componente propio del módulo Planificación). Muestra la secuencia,
// el pedido, el cliente, la dirección y, si existen, el ETA, los minutos desde la salida y el carril de
// carga. Toda la tarjeta es clickeable cuando la parada tiene ubicación: emite `focus` para que la
// página centre y abra su marcador en el mapa (mismo patrón que el piloto).
import AppCard from '@/Components/UI/AppCard.vue';

defineProps({
    stop: { type: Object, required: true },
});

const emit = defineEmits(['focus']);
</script>

<template>
    <AppCard
        :class="stop.has_point ? 'cursor-pointer transition hover:border-[#E2231A]/40' : ''"
        :role="stop.has_point ? 'button' : null"
        @click="stop.has_point && emit('focus', stop)"
    >
        <div class="flex items-start justify-between">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#E2231A]/20 text-xs font-bold text-[#ff8a80]">
                {{ stop.seq }}
            </span>
            <span v-if="stop.lane" class="rounded-md border border-neutral-700 px-2 py-0.5 text-xs text-neutral-300">
                Carril {{ stop.lane }}
            </span>
        </div>
        <dl class="mt-3 space-y-2 text-sm">
            <div>
                <dt class="text-xs text-neutral-500">Pedido</dt>
                <dd class="text-neutral-200">{{ stop.order }}</dd>
            </div>
            <div>
                <dt class="text-xs text-neutral-500">Cliente</dt>
                <dd class="text-neutral-200">{{ stop.customer }}</dd>
            </div>
            <div>
                <dt class="text-xs text-neutral-500">Dirección</dt>
                <dd class="text-neutral-300">{{ stop.address }}</dd>
            </div>
            <div v-if="stop.eta">
                <dt class="text-xs text-neutral-500">ETA</dt>
                <dd class="text-neutral-300">{{ stop.eta }}</dd>
            </div>
            <div v-if="stop.eta_minutes !== null && stop.eta_minutes !== undefined">
                <dt class="text-xs text-neutral-500">ETA (min desde la salida)</dt>
                <dd class="text-neutral-300">{{ stop.eta_minutes }}</dd>
            </div>
        </dl>
        <p v-if="stop.has_point" class="mt-3 text-xs text-[#ff8a80]">Clic para ver en el mapa →</p>
        <p v-else class="mt-3 text-xs text-neutral-600">Sin ubicación en el mapa</p>
    </AppCard>
</template>
