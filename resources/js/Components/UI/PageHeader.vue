<script setup>
// Encabezado de página reutilizable: título, subtítulo opcional y slot de acciones a la derecha.
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-white">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 text-sm text-neutral-400">{{ subtitle }}</p>
        </div>
        <div class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
