<script setup>
// Panel de inicio de la app operativa. Muestra algunas métricas rápidas (vienen del controlador).
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';

defineProps({
    stats: { type: Array, default: () => [] }, // [{ label, value, hint }]
});
</script>

<template>
    <Head title="Panel" />

    <PageHeader title="Panel operativo" subtitle="Resumen en vivo de la operación logística." />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <AppCard v-for="stat in stats" :key="stat.label">
            <p class="text-sm text-neutral-400">{{ stat.label }}</p>
            <p class="mt-1 text-2xl font-semibold text-white">{{ stat.value }}</p>
            <p v-if="stat.hint" class="mt-0.5 text-xs text-neutral-500">{{ stat.hint }}</p>
        </AppCard>
    </div>
</template>
