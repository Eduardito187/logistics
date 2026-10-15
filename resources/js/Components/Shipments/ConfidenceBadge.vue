<script setup>
// Badge de confianza de la dirección del pedido: muestra el score y su nivel (Alta/Media/Baja) con color.
// Reutiliza StatusBadge para mantener el estilo del resto de la app. Propio del módulo Pedidos.
import { computed } from 'vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';

const props = defineProps({
    score: { type: Number, default: 0 },
    level: { type: String, default: '' },
});

const levelMap = {
    HIGH: { label: 'Alta', variant: 'green' },
    MEDIUM: { label: 'Media', variant: 'amber' },
    LOW: { label: 'Baja', variant: 'red' },
};

const resolved = computed(() => levelMap[props.level] ?? { label: props.level || '—', variant: 'gray' });
const label = computed(() => `${props.score} · ${resolved.value.label}`);
</script>

<template>
    <StatusBadge :label="label" :variant="resolved.variant" />
</template>
