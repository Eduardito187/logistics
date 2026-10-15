<script setup>
// Perfil de un ayudante: datos personales (ProfileCard) y documentos (DocumentsCard). Solo lectura.
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProfileCard from '@/Components/Personnel/ProfileCard.vue';
import DocumentsCard from '@/Components/Personnel/DocumentsCard.vue';

const props = defineProps({
    helper: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
});

// Datos personales del ayudante para la grilla del perfil (valores legibles).
const fields = [
    { label: 'Cédula de identidad', value: props.helper.national_id },
    { label: 'Fecha de nacimiento', value: props.helper.birth_date },
    { label: 'Edad', value: props.helper.age != null ? `${props.helper.age} años` : null },
    { label: 'Teléfono principal', value: props.helper.phone },
    { label: 'Teléfono secundario', value: props.helper.phone_secondary },
    { label: 'Dirección', value: props.helper.address },
];

const activeBadge = props.helper.active
    ? { label: 'Activo', variant: 'green' }
    : { label: 'Inactivo', variant: 'gray' };
</script>

<template>
    <Head :title="`Ayudante · ${helper.name}`" />

    <PageHeader :title="helper.name" subtitle="Perfil del ayudante.">
        <template #actions>
            <StatusBadge :label="activeBadge.label" :variant="activeBadge.variant" />
            <Link :href="route('app.personnel.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <ProfileCard
            :name="helper.name"
            :photo-url="helper.photo_url"
            :fields="fields"
            :emergency-name="helper.emergency_contact_name"
            :emergency-phone="helper.emergency_contact_phone"
            :references="helper.references"
        />
        <DocumentsCard :documents="documents" />
    </div>
</template>
