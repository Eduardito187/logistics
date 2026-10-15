<script setup>
// Línea de tiempo append-only de los escaneos de terreno de una entrega. Cada escaneo es un hecho
// inmutable: tipo, unidad escaneada (código, NO UUID), hora, usuario, dispositivo y GPS. Si el escaneo
// tiene coordenadas y el padre pasó un onFocus, la fila es clickeable para centrarlo en el mapa.
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { scanType } from '@/Components/Deliveries/status.js';

const props = defineProps({
    scans: { type: Array, default: () => [] },
});

const emit = defineEmits(['focus']);

const focus = (scan) => {
    if (scan.has_point) {
        emit('focus', scan);
    }
};
</script>

<template>
    <ol v-if="scans.length" class="space-y-3">
        <li
            v-for="scan in scans"
            :key="scan.id"
            class="rounded-lg border border-neutral-800 bg-neutral-900/40 p-3 transition"
            :class="scan.has_point ? 'cursor-pointer hover:border-[#E2231A]/40' : ''"
            :role="scan.has_point ? 'button' : null"
            @click="focus(scan)"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#E2231A]/20 text-xs font-bold text-[#ff8a80]">
                        {{ scan.seq }}
                    </span>
                    <StatusBadge :label="scanType(scan.type).label" :variant="scanType(scan.type).variant" />
                </div>
                <span class="text-xs text-neutral-400">{{ scan.occurred_at ?? '—' }}</span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-4">
                <div>
                    <dt class="text-xs text-neutral-500">Unidad</dt>
                    <dd class="font-mono text-neutral-200">{{ scan.unit ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Usuario</dt>
                    <dd class="text-neutral-200">{{ scan.user ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Dispositivo</dt>
                    <dd class="text-neutral-300">{{ scan.device ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Ubicación</dt>
                    <dd class="text-neutral-300">
                        <template v-if="scan.has_point">{{ scan.lat }}, {{ scan.lng }}</template>
                        <template v-else>—</template>
                    </dd>
                </div>
            </dl>
            <p v-if="scan.has_point" class="mt-2 text-xs text-[#ff8a80]">Clic para ver en el mapa →</p>
        </li>
    </ol>
    <p v-else class="text-sm text-neutral-500">Esta entrega todavía no tiene escaneos.</p>
</template>
