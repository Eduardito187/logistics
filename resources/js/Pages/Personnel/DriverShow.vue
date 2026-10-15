<script setup>
// Perfil completo de un chofer: datos personales (ProfileCard), documentos (DocumentsCard) y desempeño
// operativo en un rango (PerformanceTable). El rango por defecto es del 1.º del mes a hoy; el formulario
// lo cambia recargando la página con from/to en la query. Solo lectura.
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProfileCard from '@/Components/Personnel/ProfileCard.vue';
import DocumentsCard from '@/Components/Personnel/DocumentsCard.vue';
import PerformanceTable from '@/Components/Personnel/PerformanceTable.vue';

const props = defineProps({
    driver: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
    performance: { type: Object, required: true },
    reasonLabels: { type: Object, default: () => ({}) },
});

// Datos personales del chofer para la grilla del perfil (valores legibles).
const fields = [
    { label: 'Cédula de identidad', value: props.driver.national_id },
    { label: 'Fecha de nacimiento', value: props.driver.birth_date },
    { label: 'Edad', value: props.driver.age != null ? `${props.driver.age} años` : null },
    { label: 'Número de licencia', value: props.driver.license_number },
    { label: 'Categoría de licencia', value: props.driver.license_category },
    { label: 'Vence licencia', value: props.driver.license_expiry },
    { label: 'Teléfono principal', value: props.driver.phone_primary },
    { label: 'Teléfono secundario', value: props.driver.phone_secondary },
    { label: 'Dirección', value: props.driver.address },
];

const activeBadge = props.driver.active
    ? { label: 'Activo', variant: 'green' }
    : { label: 'Inactivo', variant: 'gray' };

const from = ref(props.performance.from);
const to = ref(props.performance.to);

// Recarga el perfil con el nuevo rango de desempeño (from/to por query string).
const applyRange = () => {
    router.get(
        route('app.personnel.drivers.show', props.driver.id),
        { from: from.value, to: to.value },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="`Chofer · ${driver.name}`" />

    <PageHeader :title="driver.name" subtitle="Perfil del chofer y desempeño operativo.">
        <template #actions>
            <StatusBadge :label="activeBadge.label" :variant="activeBadge.variant" />
            <Link :href="route('app.personnel.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <ProfileCard
            :name="driver.name"
            :photo-url="driver.photo_url"
            :fields="fields"
            :emergency-name="driver.emergency_contact_name"
            :emergency-phone="driver.emergency_contact_phone"
            :references="driver.references"
        />
        <DocumentsCard :documents="documents" />
    </div>

    <AppCard title="Desempeño operativo" class="mb-4">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyRange">
            <div>
                <label class="block text-xs text-neutral-500">Desde</label>
                <input v-model="from" type="date" class="mt-1 rounded-lg border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-200" />
            </div>
            <div>
                <label class="block text-xs text-neutral-500">Hasta</label>
                <input v-model="to" type="date" class="mt-1 rounded-lg border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-sm text-neutral-200" />
            </div>
            <button type="submit" class="rounded-lg bg-[#E2231A] px-4 py-1.5 text-sm font-medium text-white transition hover:bg-[#c21e16]">
                Aplicar
            </button>
        </form>
    </AppCard>

    <PerformanceTable :performance="performance" :reason-labels="reasonLabels" />
</template>
