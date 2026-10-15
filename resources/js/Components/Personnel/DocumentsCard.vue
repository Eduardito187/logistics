<script setup>
// Tarjeta con los documentos de una persona de la flota. Cada documento muestra su tipo legible, fechas
// y, si hay archivo con URL temporal disponible, un enlace para abrirlo (si el disco no soporta URL
// temporal, no se muestra enlace). Solo lectura.
import AppCard from '@/Components/UI/AppCard.vue';

defineProps({
    // Lista de { document_type, expires_on, issued_on, note, file_url }.
    documents: { type: Array, default: () => [] },
});
</script>

<template>
    <AppCard title="Documentos">
        <p v-if="documents.length === 0" class="text-sm text-neutral-500">Sin documentos registrados.</p>
        <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div
                v-for="(doc, i) in documents"
                :key="i"
                class="rounded-lg border border-neutral-800 bg-neutral-900/40 p-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="text-sm font-medium text-neutral-200">{{ doc.document_type }}</span>
                    <a
                        v-if="doc.file_url"
                        :href="doc.file_url"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 text-xs text-[#ff8a80] hover:underline"
                    >
                        Abrir archivo →
                    </a>
                </div>
                <dl class="mt-2 space-y-1 text-xs">
                    <div class="flex justify-between">
                        <dt class="text-neutral-500">Vence</dt>
                        <dd class="text-neutral-300">{{ doc.expires_on ?? '—' }}</dd>
                    </div>
                    <div v-if="doc.issued_on" class="flex justify-between">
                        <dt class="text-neutral-500">Emitido</dt>
                        <dd class="text-neutral-300">{{ doc.issued_on }}</dd>
                    </div>
                </dl>
                <p v-if="doc.note" class="mt-2 text-xs text-neutral-500">{{ doc.note }}</p>
            </div>
        </div>
    </AppCard>
</template>
