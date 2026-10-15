<script setup>
// Badge del estado visible del seguimiento (vocabulario propio del contexto tracking, distinto del
// estado interno del envío). Traduce el valor crudo del enum VisibleStatus a etiqueta legible en
// español y color semántico, reutilizando el StatusBadge compartido. Componente propio del módulo.
import StatusBadge from '@/Components/UI/StatusBadge.vue';

defineProps({
    status: { type: String, default: '' },
});

// Mapa de estado visible (VisibleStatus) → etiqueta legible + variante de color.
const statusMap = {
    REGISTERED: { label: 'Registrado', variant: 'gray' },
    PREPARED: { label: 'Preparado', variant: 'amber' },
    ON_THE_WAY: { label: 'En camino', variant: 'blue' },
    ARRIVING: { label: 'Llegando', variant: 'amber' },
    DELIVERED: { label: 'Entregado', variant: 'green' },
    FAILED: { label: 'Fallido', variant: 'red' },
};
const of = (s) => statusMap[s] ?? { label: s, variant: 'gray' };
</script>

<template>
    <StatusBadge :label="of(status).label" :variant="of(status).variant" />
</template>
