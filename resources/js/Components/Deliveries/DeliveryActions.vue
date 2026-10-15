<script setup>
// Acciones de ESCRITURA sobre una entrega (el operador ACTÚA, no solo mira). Cada acción abre un
// formulario en un modal y despacha, vía su controlador, un Command del dominio por el CommandBus:
//   - "Registrar prueba de entrega" → RecordProofOfDelivery (confirmación + foto opcional).
//   - "Registrar entrega fallida"   → RecordFailedDelivery (motivo tipificado).
//   - "Reportar incidente"          → ReportVehicleIncident (avería/accidente → reprogramación).
// Usa useForm de Inertia: estado `processing`, errores de validación del backend y flash de éxito/error
// (que muestra el AppLayout). Los escaneos son append-only: estas acciones agregan hechos, no editan.
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({
    delivery: { type: Object, required: true },
});

// El AppLayout compartido no muestra los mensajes flash; los surfaceamos aquí (propio del módulo) para
// confirmar el resultado de la acción sin tocar archivos compartidos.
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

// La entrega solo se puede entregar o fallar si está cargada o en tránsito (regla del dominio). Fuera de
// esos estados ocultamos esas acciones para no ofrecer algo que el dominio va a rechazar.
const isActionable = computed(() => ['LOADED', 'IN_TRANSIT'].includes(props.delivery.status));

// El incidente es del vehículo: solo tiene sentido si hay un camión asignado a la entrega.
const hasVehicle = computed(() => !!props.delivery.vehicle && props.delivery.vehicle !== '—');

// Código de unidad por defecto: la referencia del pedido. El operador puede ajustarlo.
const defaultUnit = computed(() => (props.delivery.order && props.delivery.order !== '—' ? props.delivery.order : ''));

// Modal activo: null | 'pod' | 'fail' | 'incident'.
const activeModal = ref(null);
const openModal = (name) => {
    activeModal.value = name;
};
const closeModal = () => {
    activeModal.value = null;
};

// Motivos de fallo tipificados (FailureReason del dominio) con etiqueta en español.
const failureReasons = [
    { value: 'CUSTOMER_ABSENT', label: 'Cliente ausente' },
    { value: 'WRONG_ADDRESS', label: 'Dirección incorrecta' },
    { value: 'REJECTED', label: 'Rechazado' },
    { value: 'DAMAGED', label: 'Dañado' },
    { value: 'OTHER', label: 'Otro' },
];

// Gravedad y tipo del incidente (IncidentSeverity / IncidentType del dominio).
const incidentSeverities = [
    { value: 'MINOR', label: 'Leve (continúa con retraso)' },
    { value: 'MAJOR', label: 'Grave (reprograma al día siguiente)' },
];
const incidentTypes = [
    { value: 'BREAKDOWN', label: 'Avería' },
    { value: 'ACCIDENT', label: 'Accidente' },
];

// Un formulario por acción. Se envían con router.post vía useForm.
const podForm = useForm({ confirmation: '', unit_id: defaultUnit.value, photo_url: '' });
const failForm = useForm({ reason: '', unit_id: defaultUnit.value });
const incidentForm = useForm({ severity: '', type: '', note: '' });

const submitPod = () => {
    podForm.post(route('app.deliveries.pod', props.delivery.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            podForm.reset('confirmation', 'photo_url');
        },
    });
};

const submitFail = () => {
    failForm.post(route('app.deliveries.fail', props.delivery.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            failForm.reset('reason');
        },
    });
};

const submitIncident = () => {
    incidentForm.post(route('app.deliveries.incident', props.delivery.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            incidentForm.reset();
        },
    });
};
</script>

<template>
    <div>
        <!-- Mensajes flash del resultado de la última acción. -->
        <div v-if="flashSuccess" class="mb-4 rounded-lg border border-green-800/60 bg-green-950/40 px-4 py-2 text-sm text-green-300">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="mb-4 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-2 text-sm text-red-300">
            {{ flashError }}
        </div>

        <!-- Barra de acciones -->
        <div class="flex flex-wrap gap-3">
            <button
                v-if="isActionable"
                type="button"
                class="rounded-lg bg-[#E2231A] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#c21d15]"
                @click="openModal('pod')"
            >
                Registrar prueba de entrega
            </button>
            <button
                v-if="isActionable"
                type="button"
                class="rounded-lg border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition hover:bg-neutral-800"
                @click="openModal('fail')"
            >
                Registrar entrega fallida
            </button>
            <button
                v-if="hasVehicle"
                type="button"
                class="rounded-lg border border-amber-700/60 px-4 py-2 text-sm font-medium text-amber-300 transition hover:bg-amber-950/40"
                @click="openModal('incident')"
            >
                Reportar incidente del vehículo
            </button>
            <p v-if="!isActionable && !hasVehicle" class="text-sm text-neutral-500">
                No hay acciones disponibles para el estado actual de la entrega.
            </p>
        </div>

        <!-- Modal genérico (overlay + tarjeta). Propio del módulo: no se reusa un modal compartido. -->
        <teleport to="body">
            <div
                v-if="activeModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                @click.self="closeModal"
            >
                <div class="w-full max-w-md rounded-xl border border-neutral-800 bg-neutral-900 p-5 shadow-xl">
                    <!-- Prueba de entrega -->
                    <form v-if="activeModal === 'pod'" @submit.prevent="submitPod">
                        <h3 class="mb-4 text-base font-semibold text-neutral-100">Registrar prueba de entrega</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Confirmación del cliente</label>
                                <input
                                    v-model="podForm.confirmation"
                                    type="text"
                                    maxlength="512"
                                    placeholder="Quién recibió / referencia de firma"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-[#E2231A] focus:outline-none"
                                />
                                <p v-if="podForm.errors.confirmation" class="mt-1 text-xs text-red-400">{{ podForm.errors.confirmation }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Unidad / bulto</label>
                                <input
                                    v-model="podForm.unit_id"
                                    type="text"
                                    maxlength="64"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-[#E2231A] focus:outline-none"
                                />
                                <p v-if="podForm.errors.unit_id" class="mt-1 text-xs text-red-400">{{ podForm.errors.unit_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">URL de foto (opcional)</label>
                                <input
                                    v-model="podForm.photo_url"
                                    type="url"
                                    maxlength="2048"
                                    placeholder="https://…"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-[#E2231A] focus:outline-none"
                                />
                                <p v-if="podForm.errors.photo_url" class="mt-1 text-xs text-red-400">{{ podForm.errors.photo_url }}</p>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-3">
                            <button type="button" class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 transition hover:bg-neutral-800" @click="closeModal">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="podForm.processing" class="rounded-lg bg-[#E2231A] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#c21d15] disabled:opacity-50">
                                {{ podForm.processing ? 'Registrando…' : 'Registrar' }}
                            </button>
                        </div>
                    </form>

                    <!-- Entrega fallida -->
                    <form v-else-if="activeModal === 'fail'" @submit.prevent="submitFail">
                        <h3 class="mb-4 text-base font-semibold text-neutral-100">Registrar entrega fallida</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Motivo</label>
                                <Select v-model="failForm.reason" :options="failureReasons" placeholder="Selecciona un motivo…" />
                                <p v-if="failForm.errors.reason" class="mt-1 text-xs text-red-400">{{ failForm.errors.reason }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Unidad / bulto</label>
                                <input
                                    v-model="failForm.unit_id"
                                    type="text"
                                    maxlength="64"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-[#E2231A] focus:outline-none"
                                />
                                <p v-if="failForm.errors.unit_id" class="mt-1 text-xs text-red-400">{{ failForm.errors.unit_id }}</p>
                            </div>
                            <p class="text-xs text-neutral-500">
                                El registro cierra la entrega como fallida y avanza la ruta del chofer a la siguiente parada.
                            </p>
                        </div>
                        <div class="mt-5 flex justify-end gap-3">
                            <button type="button" class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 transition hover:bg-neutral-800" @click="closeModal">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="failForm.processing" class="rounded-lg bg-[#E2231A] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#c21d15] disabled:opacity-50">
                                {{ failForm.processing ? 'Registrando…' : 'Registrar fallo' }}
                            </button>
                        </div>
                    </form>

                    <!-- Incidente del vehículo -->
                    <form v-else-if="activeModal === 'incident'" @submit.prevent="submitIncident">
                        <h3 class="mb-4 text-base font-semibold text-neutral-100">Reportar incidente del vehículo</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Gravedad</label>
                                <Select v-model="incidentForm.severity" :options="incidentSeverities" placeholder="Selecciona la gravedad…" />
                                <p v-if="incidentForm.errors.severity" class="mt-1 text-xs text-red-400">{{ incidentForm.errors.severity }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Tipo</label>
                                <Select v-model="incidentForm.type" :options="incidentTypes" placeholder="Selecciona el tipo…" />
                                <p v-if="incidentForm.errors.type" class="mt-1 text-xs text-red-400">{{ incidentForm.errors.type }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-neutral-400">Nota (opcional)</label>
                                <textarea
                                    v-model="incidentForm.note"
                                    rows="3"
                                    maxlength="1000"
                                    placeholder="Detalle del incidente…"
                                    class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-[#E2231A] focus:outline-none"
                                ></textarea>
                                <p v-if="incidentForm.errors.note" class="mt-1 text-xs text-red-400">{{ incidentForm.errors.note }}</p>
                            </div>
                            <p class="text-xs text-neutral-500">
                                La ruta abierta del camión se resuelve sola. Un incidente grave reprograma las paradas pendientes al día siguiente.
                            </p>
                        </div>
                        <div class="mt-5 flex justify-end gap-3">
                            <button type="button" class="rounded-lg border border-neutral-700 px-4 py-2 text-sm text-neutral-300 transition hover:bg-neutral-800" @click="closeModal">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="incidentForm.processing" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-500 disabled:opacity-50">
                                {{ incidentForm.processing ? 'Reportando…' : 'Reportar incidente' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </div>
</template>
