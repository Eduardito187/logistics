<script setup>
// Tarjeta de perfil de una persona de la flota (chofer o ayudante): foto cuadrada (si hay URL), nombre
// y una grilla de datos personales. El contacto de emergencia y las referencias son comunes a ambos;
// los campos propios de cada rol llegan en `fields`. Valores legibles, nunca UUIDs.
import AppCard from '@/Components/UI/AppCard.vue';

defineProps({
    name: { type: String, required: true },
    photoUrl: { type: String, default: null },
    // Lista de { label, value } con los datos personales a mostrar.
    fields: { type: Array, default: () => [] },
    emergencyName: { type: String, default: null },
    emergencyPhone: { type: String, default: null },
    // Lista de { name, relationship, phone, note }.
    references: { type: Array, default: () => [] },
});

const show = (value) => (value !== null && value !== undefined && value !== '' ? value : '—');
</script>

<template>
    <AppCard title="Perfil">
        <div class="flex flex-col gap-5 sm:flex-row">
            <div class="shrink-0">
                <img
                    v-if="photoUrl"
                    :src="photoUrl"
                    :alt="name"
                    class="h-28 w-28 rounded-lg object-cover ring-1 ring-neutral-700"
                />
                <div
                    v-else
                    class="flex h-28 w-28 items-center justify-center rounded-lg bg-neutral-800 text-3xl font-bold text-neutral-500 ring-1 ring-neutral-700"
                >
                    {{ name.charAt(0).toUpperCase() }}
                </div>
            </div>

            <div class="flex-1">
                <h3 class="mb-3 text-lg font-semibold text-neutral-100">{{ name }}</h3>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="field in fields" :key="field.label">
                        <dt class="text-xs text-neutral-500">{{ field.label }}</dt>
                        <dd class="text-sm text-neutral-200">{{ show(field.value) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-5 border-t border-neutral-800 pt-4">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">Contacto de emergencia</h4>
            <p class="text-sm text-neutral-200">
                {{ show(emergencyName) }}
                <span v-if="emergencyPhone" class="text-neutral-400"> · {{ emergencyPhone }}</span>
            </p>
        </div>

        <div class="mt-5 border-t border-neutral-800 pt-4">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">Referencias</h4>
            <p v-if="references.length === 0" class="text-sm text-neutral-500">Sin referencias.</p>
            <ul v-else class="space-y-2">
                <li v-for="(ref, i) in references" :key="i" class="text-sm">
                    <span class="text-neutral-200">{{ ref.name }}</span>
                    <span v-if="ref.relationship" class="text-neutral-400"> · {{ ref.relationship }}</span>
                    <span v-if="ref.phone" class="text-neutral-400"> · {{ ref.phone }}</span>
                    <span v-if="ref.note" class="block text-xs text-neutral-500">{{ ref.note }}</span>
                </li>
            </ul>
        </div>
    </AppCard>
</template>
