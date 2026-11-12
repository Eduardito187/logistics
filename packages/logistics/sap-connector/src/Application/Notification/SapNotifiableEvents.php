<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Notification;

/**
 * Catálogo de hechos de despacho que se le pueden avisar a SAP (fire-and-forget).
 *
 * Clave = event_name EXACTO tal como lo graba el outbox (verificado contra los eventos de dominio de
 * cada contexto); valor = etiqueta legible en inglés para el admin (se traduce con __()). El panel de
 * Configuración ofrece este catálogo como opciones de «Events to notify», y el listado de avisos lo usa
 * para mostrar el evento con nombre humano. Agregar un evento acá lo vuelve seleccionable; qué eventos
 * se avisan de verdad lo decide la config `logistics-sap-connector.notifications.events`.
 */
final class SapNotifiableEvents
{
    /**
     * @var array<string, string>
     */
    public const CATALOG = [
        'shipments.shipment_received' => 'Shipment received',
        'shipments.shipment_rescheduled' => 'Shipment rescheduled',
        'shipments.shipment_cancelled' => 'Shipment cancelled',
        'execution.shipment_prepared' => 'Shipment prepared',
        'execution.shipment_loaded' => 'Shipment loaded',
        'execution.shipment_departed' => 'Shipment departed',
        'execution.shipment_delivered' => 'Shipment delivered',
        'execution.delivery_failed' => 'Delivery failed',
        'execution.route_stop_attached' => 'Shipment assigned to vehicle',
        'execution.stop_arrived' => 'Vehicle arrived at stop',
        'execution.driver_route_started' => 'Driver route started',
        'execution.driver_route_closed' => 'Driver route closed',
        'execution.delivery_evidence_validated' => 'Delivery evidence validated',
        'execution.node_reception_opened' => 'Node reception opened',
        'execution.node_reception_reconciled' => 'Node reception reconciled',
        'carriers.carrier_trip_started' => 'Carrier trip started',
        'carriers.carrier_trip_closed' => 'Carrier trip closed',
        'fleet.vehicle_marked_unfit' => 'Vehicle marked unfit',
        'fleet.vehicle_cleared' => 'Vehicle cleared for dispatch',
        'telemetry.vehicle_route_deviated' => 'Vehicle route deviation',
        'telemetry.vehicle_speeding_sustained' => 'Vehicle sustained speeding',
        'catalog.product_physical_spec_changed' => 'Product measurements changed',
        // `telemetry.vehicle_position_reported` NO va: es de alto volumen (un aviso por lote de GPS).
        'orders.order_cancelled' => 'Order cancelled',
        'invoicing.invoice_issued' => 'Invoice issued',
    ];

    /**
     * Eventos avisados por defecto (el catálogo completo).
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Etiqueta legible (sin traducir) de un evento; si no está en el catálogo, el nombre humanizado.
     */
    public static function label(string $eventName): string
    {
        if (isset(self::CATALOG[$eventName])) {
            return self::CATALOG[$eventName];
        }

        $short = str_contains($eventName, '.') ? substr($eventName, (int) strrpos($eventName, '.') + 1) : $eventName;

        return ucfirst(str_replace('_', ' ', $short));
    }
}
