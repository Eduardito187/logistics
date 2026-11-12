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
        'orders.order_cancelled' => 'Order cancelled',
        'invoicing.invoice_issued' => 'Invoice issued',
    ];

    /**
     * Eventos avisados por defecto (todo el catálogo).
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
