<script setup>
// Badge de estado reutilizable, con variantes de color. Mapea un `variant` a clases Tailwind; por
// defecto gris. Las pantallas traducen su estado de negocio a un variant y una etiqueta.
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    variant: {
        type: String,
        default: 'gray',
        validator: (v) => ['gray', 'green', 'red', 'amber', 'blue'].includes(v),
    },
});

const classes = computed(() => ({
    gray: 'bg-neutral-700/40 text-neutral-300 ring-neutral-600/40',
    green: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
    red: 'bg-[#E2231A]/15 text-[#ff8a80] ring-[#E2231A]/30',
    amber: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
    blue: 'bg-blue-500/15 text-blue-300 ring-blue-500/30',
}[props.variant]));
</script>

<template>
    <span
        class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
