<script setup>
// Detalle de un seguimiento: cabecera con estado visible, datos del envío y ETA, y un mapa con la última
// posición GPS reportada del camión (si la hay). Reutiliza RouteMap pasando la posición como una única
// parada; sin trazado planificado (el seguimiento no tiene geometría de ruta). El token público nunca se
// muestra crudo: llega ya enmascarado desde el controlador. Patrón de referencia: Operaciones/Rutas.
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import RouteMap from '@/Components/Map/RouteMap.vue';
import TrackingStatusBadge from '@/Components/Tracking/TrackingStatusBadge.vue';

defineProps({
    tracking: { type: Object, required: true },
    map: { type: Object, default: () => ({ stops: [], planned: [], actual: [] }) },
});
</script>

<template>
    <Head :title="`Seguimiento ${tracking.order}`" />

    <PageHeader :title="`Envío · ${tracking.order}`" :subtitle="tracking.customer">
        <template #actions>
            <TrackingStatusBadge :status="tracking.status" />
            <Link :href="route('app.tracking.index')" class="rounded-lg border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 transition hover:bg-neutral-800">
                ← Volver
            </Link>
        </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <!-- Datos del seguimiento -->
        <AppCard title="Datos del envío" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-neutral-500">Pedido</dt>
                    <dd class="text-neutral-200">{{ tracking.order }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Cliente</dt>
                    <dd class="text-neutral-200">{{ tracking.customer }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Dirección</dt>
                    <dd class="text-neutral-300">{{ tracking.address }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Estado</dt>
                    <dd class="mt-1"><TrackingStatusBadge :status="tracking.status" /></dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">ETA</dt>
                    <dd class="text-neutral-300">{{ tracking.eta ?? 'No disponible' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Paradas restantes</dt>
                    <dd class="text-neutral-200">{{ tracking.remaining_stops }}</dd>
                </div>
                <div v-if="tracking.route_position !== null">
                    <dt class="text-xs text-neutral-500">Posición en la ruta</dt>
                    <dd class="text-neutral-200">{{ tracking.route_position }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-500">Token público</dt>
                    <dd class="font-mono text-neutral-400">{{ tracking.token_masked }}</dd>
                </div>
            </dl>
        </AppCard>

        <!-- Mapa con la última posición GPS -->
        <AppCard title="Última posición del camión" class="lg:col-span-2">
            <template v-if="tracking.has_point">
                <RouteMap :stops="map.stops" :planned="map.planned" :actual="map.actual" />
                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-neutral-400">
                    <span>Lat {{ tracking.last_lat }}, Lng {{ tracking.last_lng }}</span>
                    <span v-if="tracking.last_position_at">Reportada: {{ tracking.last_position_at }}</span>
                </div>
            </template>
            <p v-else class="py-10 text-center text-sm text-neutral-500">
                Todavía no hay una posición GPS reportada para este envío.
            </p>
        </AppCard>
    </div>
</template>
