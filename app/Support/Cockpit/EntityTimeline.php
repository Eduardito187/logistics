<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Línea de tiempo (actividad) de UNA entidad para su ficha 360: fusiona los HECHOS de dominio del outbox
 * (`outbox_messages`) y las ACCIONES de administración de la auditoría (`access_audit_log`) que refieren a
 * esa entidad (por su id y sus referencias), en orden cronológico descendente y con etiquetas legibles.
 *
 * SOLO lectura y tolerante (cada consulta en su try/catch; tabla ausente → []). Hace visible "qué pasó con
 * este pedido/envío/vehículo y cuándo", sin inventar nada: si no hay eventos, la línea de tiempo va vacía.
 */
final class EntityTimeline
{
    use ReadsCockpitAggregates;

    /** Máximo de eventos a mostrar. */
    private const int LIMIT = 30;

    /**
     * Mapa curado de evento de dominio → etiqueta legible (español, capa cockpit). Lo no mapeado se
     * humaniza por el último segmento (ver humanize()).
     *
     * @var array<string, string>
     */
    private const array EVENT_LABELS = [
        'shipments.shipment_accepted' => 'Envío aceptado',
        'execution.shipment_prepared' => 'Envío preparado',
        'execution.delivery_issue_reported' => 'Inconveniente reportado',
        'execution.proof_of_delivery_recorded' => 'Prueba de entrega',
        'execution.delivery_failed' => 'Entrega fallida',
        'execution.node_reception_reconciled' => 'Recepción conciliada',
        'planning.plan_generated' => 'Plan de reparto generado',
        'planning.auto_plan_requested' => 'Auto-planificación solicitada',
        'planning.plan_published' => 'Plan publicado',
        'tracking.shipment_placed_on_route' => 'Puesto en ruta',
        'tracking.status_changed' => 'Cambio de estado de seguimiento',
        'tracking.tracking_opened' => 'Seguimiento abierto',
        'tracking.unit_status_changed' => 'Cambio de estado de producto',
        'tracking.unit_tracking_opened' => 'Seguimiento de producto abierto',
        'labels.labels_emitted' => 'Etiquetas emitidas',
        'fleet.vehicle_status_changed' => 'Cambio de estado del vehículo',
        'fleet.crew_assembled' => 'Tripulación armada',
        'fleet.documents_expiry_alerted' => 'Documentos por vencer',
        'fleetops.departure_registered' => 'Salida registrada',
        'fleetops.departure_closed' => 'Salida cerrada',
        'fleetops.expense_recorded' => 'Gasto registrado',
        'fleetops.fuel_price_set' => 'Precio de combustible fijado',
        'fleetops.fuel_price_activated' => 'Precio de combustible activado',
        'fleetops.maintenance_scheduled' => 'Mantenimiento agendado',
        'fleetops.maintenance_started' => 'Mantenimiento iniciado',
        'fleetops.maintenance_completed' => 'Mantenimiento completado',
        'fleetops.maintenance_plan_defined' => 'Plan de mantenimiento definido',
        'fleetops.odometer_recorded' => 'Odómetro registrado',
        'fleetops.refuel_recorded' => 'Carga de combustible',
        'fleetops.vehicle_profile_defined' => 'Ficha técnica definida',
        'inventory.stock_received' => 'Stock recibido',
        'network.node_registered' => 'Nodo registrado',
        'notifications.notification_queued' => 'Notificación enviada',
        'promise.promise_expired' => 'Promesa de entrega vencida',
        'sap.stock_transfer_posted' => 'Traspaso de stock a SAP',
        'telemetry.alert_raised' => 'Alerta de telemetría',
        'telemetry.alert_acknowledged' => 'Alerta reconocida',
        'telemetry.geofence_redrawn' => 'Geocerca redibujada',
        'telemetry.trip_started' => 'Viaje iniciado',
        'telemetry.trip_completed' => 'Viaje completado',
        // Acciones de administración (auditoría) que dispara la Torre de Control.
        'cockpit.customers_notified' => 'Clientes notificados',
        'cockpit.routes_replanned' => 'Rutas replanificadas',
        'cockpit.reservations_released' => 'Reservas de cupo liberadas',
    ];

    /**
     * Línea de tiempo de la entidad identificada por `$ids` (su id y referencias: external_id, order_ref…).
     *
     * @param  list<string>                                                                       $ids
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => trim($id) !== '')));
        if ($ids === []) {
            return [];
        }

        $events = array_merge($this->domainEvents($ids), $this->adminActions($ids));

        // Orden cronológico descendente (lo más reciente primero) y recorte.
        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, self::LIMIT);
    }

    /**
     * Hechos de dominio del outbox que refieren a la entidad (por aggregate_id).
     *
     * @param  list<string>                                                                       $ids
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    private function domainEvents(array $ids): array
    {
        try {
            $out = [];
            foreach (DB::table('outbox_messages')
                ->whereIn('aggregate_id', $ids)
                ->orderByDesc('created_at')
                ->limit(self::LIMIT)
                ->get(['event_name', 'created_at']) as $row) {
                $at = is_string($row->created_at) ? $row->created_at : '';
                $out[] = [
                    'at' => $at,
                    'when' => $this->humanWhen($at),
                    'label' => $this->label(is_string($row->event_name) ? $row->event_name : ''),
                    'kind' => 'domain',
                    'actor' => null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Acciones de administración de la auditoría que refieren a la entidad (por subject_id).
     *
     * @param  list<string>                                                                       $ids
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    private function adminActions(array $ids): array
    {
        try {
            $out = [];
            foreach (DB::table('access_audit_log')
                ->whereIn('subject_id', $ids)
                ->orderByDesc('occurred_at')
                ->limit(self::LIMIT)
                ->get(['action', 'actor_label', 'occurred_at']) as $row) {
                $at = is_string($row->occurred_at) ? $row->occurred_at : '';
                $out[] = [
                    'at' => $at,
                    'when' => $this->humanWhen($at),
                    'label' => $this->label(is_string($row->action) ? $row->action : ''),
                    'kind' => 'admin',
                    'actor' => is_string($row->actor_label) && $row->actor_label !== '' ? $row->actor_label : null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Etiqueta legible del evento/acción: mapa curado, o humanizado por el último segmento. */
    private function label(string $event): string
    {
        if ($event === '') {
            return 'Evento';
        }

        if (isset(self::EVENT_LABELS[$event])) {
            return self::EVENT_LABELS[$event];
        }

        $last = $event;
        if (($pos = strrpos($event, '.')) !== false) {
            $last = substr($event, $pos + 1);
        }

        return ucfirst(str_replace('_', ' ', $last));
    }

    /** Fecha legible corta "19 oct. 09:00", tolerante. */
    private function humanWhen(string $iso): string
    {
        if (trim($iso) === '') {
            return '';
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM HH:mm');
        } catch (Throwable) {
            return '';
        }
    }
}
