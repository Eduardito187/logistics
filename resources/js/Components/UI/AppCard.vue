<script setup>
// Tarjeta contenedora reutilizable: borde sutil, fondo oscuro, título opcional y slot de cuerpo.
defineProps({
    title: { type: String, default: null },
});
</script>

<template>
    <div class="rounded-xl border border-neutral-800 bg-neutral-900/50">
        <div v-if="title || $slots.header" class="flex items-center justify-between border-b border-neutral-800 px-4 py-3">
            <h2 class="text-sm font-semibold text-neutral-200">{{ title }}</h2>
            <slot name="header" />
        </div>
        <div :class="$slots.header || title ? 'p-4' : 'p-4'">
            <slot />
        </div>
    </div>
</template>
