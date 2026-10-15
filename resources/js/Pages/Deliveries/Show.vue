<script setup>
// Detalle de una entrega: cabecera con estado, tarjeta de prueba de entrega (confirmación, foto, hora)
// o del motivo de fallo, mapa opcional con los puntos de los escaneos y la línea de tiempo append-only
// de escaneos. Clic en un escaneo con ubicación centra su marcador en el mapa (vía ref del RouteMap),
// mismo patrón que el detalle de Rutas del piloto.
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';
import ScanTimeline from '@/Components/Deliveries/ScanTimeline.vue';
import DeliveryActions from '@/Components/Deliveries/DeliveryActions.vue';
import { deliveryStatus, failureReason } from '@/Components/Deliveries/status.js';

const props = defineProps({
    delivery: { type: Object, required: true },
    scans: { type: Array, default: () => [] },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [], geometry: [] }) },
});

const mapRef = ref(null);

const hasMap = computed(() => props.map.stops.length > 0);
const isFailed = computed(() => props.delivery.status === 'FAILED');
const hasPod = computed(
    () => props.delivery.pod_confirmation || props.delivery.pod_photo_url || props.delivery.pod_captured_at,
);

const focusScan = (scan) => {
    if (scan.has_point) {
        mapRef.value?.focusStop(scan.seq);
    }
};
</script>

<template>
    <Head :title="`Entrega ${delivery.order}`" />

    <PageHeader :title="`Entrega · ${delivery.order}`" :subtitle="`Cliente ${delivery.customer}`">
        <template #actions>
            <StatusBadge :label="deliveryStatus(delivery.status).label" :variant="deliveryStatus(delivery.status).variant" />
            <Link
                :href="route('app.deliveries.index')"
                class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800"
            >
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <!-- Datos generales de la entrega -->
        <AppCard title="Entrega">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Pedido</dt>
                    <dd class="text-neutral-200">{{ delivery.order }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Cliente</dt>
                    <dd class="text-neutral-200">{{ delivery.customer }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Dirección</dt>
                    <dd class="text-neutral-300">{{ delivery.address }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Vehículo</dt>
                    <dd class="text-neutral-200">{{ delivery.vehicle }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Estado</dt>
                    <dd class="mt-1">
                        <StatusBadge :label="deliveryStatus(delivery.status).label" :variant="deliveryStatus(delivery.status).variant" />
                    </dd>
                </div>
            </dl>
        </AppCard>

        <!-- Prueba de entrega o motivo de fallo -->
        <AppCard :title="isFailed ? 'Motivo de fallo' : 'Prueba de entrega'">
            <div v-if="isFailed" class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Motivo</dt>
                    <dd class="mt-1">
                        <StatusBadge :label="failureReason(delivery.failure_reason)" variant="red" />
                    </dd>
                </div>
                <div v-if="delivery.pod_captured_at">
                    <dt class="text-xs text-neutral-500">Fecha</dt>
                    <dd class="text-neutral-300">{{ delivery.pod_captured_at }}</dd>
                </div>
            </div>
            <div v-else-if="hasPod" class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Confirmación del cliente</dt>
                    <dd class="text-neutral-200">{{ delivery.pod_confirmation ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Fecha de entrega</dt>
                    <dd class="text-neutral-300">{{ delivery.pod_captured_at ?? '—' }}</dd>
                </div>
                <div v-if="delivery.pod_photo_url">
                    <dt class="text-xs text-neutral-500">Foto</dt>
                    <dd class="mt-1">
                        <a :href="delivery.pod_photo_url" target="_blank" rel="noopener" class="inline-block">
                            <img
                                :src="delivery.pod_photo_url"
                                alt="Foto de la prueba de entrega"
                                class="max-h-48 rounded-lg border border-neutral-800 object-cover"
                            />
                        </a>
                    </dd>
                </div>
            </div>
            <p v-else class="text-sm text-neutral-500">Esta entrega todavía no tiene prueba de entrega.</p>
        </AppCard>
    </div>

    <!-- Acciones de escritura: el operador actúa sobre la entrega (POD, fallo, incidente del vehículo). -->
    <AppCard title="Acciones" class="mt-5">
        <DeliveryActions :delivery="delivery" />
    </AppCard>

    <!-- Mapa opcional con los puntos de los escaneos -->
    <AppCard v-if="hasMap" title="Ubicaciones de los escaneos" class="mt-5">
        <RouteMap ref="mapRef" :stops="map.stops" :planned="map.planned" :actual="map.actual" :geometry="map.geometry" :osrm-host="map.osrmHost" />
        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-neutral-400">
            <span class="inline-flex items-center gap-2">
                <span class="inline-block w-5" style="border-top:3px solid #E2231A"></span> Recorrido por escaneos (GPS)
            </span>
        </div>
    </AppCard>

    <h2 class="mb-3 mt-6 text-sm font-semibold text-neutral-300">Escaneos (hechos de terreno, append-only)</h2>
    <ScanTimeline :scans="scans" @focus="focusScan" />
</template>
