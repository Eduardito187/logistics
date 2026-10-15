<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Variable;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Notifications\Domain\Contract\NotificationVariableProvider;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Throwable;

/**
 * Implementación del proveedor de variables: arma el mapa de interpolación desde los datos reales del
 * envío y del tracking, leyendo por nombre de tabla con el query builder (no acopla contra los modelos
 * Eloquent de otros packages).
 *
 * - {{order}}      ← shipments.external_id (referencia humana del pedido; si no hay, el propio id).
 * - {{customer}}   ← shipments.customer_name.
 * - {{address}}    ← shipments.address_text (+ address_reference entre paréntesis si existe).
 * - {{stops}}      ← tracking_shipments.remaining_stops (paradas que faltan antes de la entrega).
 * - {{eta}}        ← tracking_shipments.eta (hora estimada, formateada legible).
 *
 * Es tolerante y degrada sin romper: si una tabla no existe o la consulta falla, deja las claves
 * correspondientes vacías para no filtrar marcadores ni cortar el envío. El tipo de mensaje se recibe
 * para poder variar el set de variables en el futuro (p. ej. no resolver tracking en mensajes previos
 * a la ruta), pero hoy todas las claves se arman igual porque las ausentes quedan vacías.
 */
final readonly class ShipmentNotificationVariableProvider implements NotificationVariableProvider
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private string $etaFormat = 'Y-m-d H:i',
    ) {}

    public function variablesFor(string $shipmentId, MessageType $messageType): array
    {
        $variables = [];

        $shipment = $this->shipmentRow($shipmentId);
        if ($shipment !== null) {
            $externalId = $this->stringOrNull($shipment->external_id ?? null);
            // {{order}}: referencia humana del pedido; si falta, cae al id crudo del envío.
            $variables['order'] = $externalId ?? $shipmentId;

            $customer = $this->stringOrNull($shipment->customer_name ?? null);
            if ($customer !== null) {
                $variables['customer'] = $customer;
            }

            $address = $this->composeAddress(
                $this->stringOrNull($shipment->address_text ?? null),
                $this->stringOrNull($shipment->address_reference ?? null),
            );
            if ($address !== null) {
                $variables['address'] = $address;
            }
        }

        $tracking = $this->trackingRow($shipmentId);
        if ($tracking !== null) {
            if (isset($tracking->remaining_stops) && is_numeric($tracking->remaining_stops)) {
                $variables['stops'] = (string) max(0, (int) $tracking->remaining_stops);
            }

            $eta = $this->formatEta($this->stringOrNull($tracking->eta ?? null));
            if ($eta !== null) {
                $variables['eta'] = $eta;
            }
        }

        return $variables;
    }

    /**
     * Lee la fila del envío (referencia, cliente, dirección). Null si no existe o la consulta falla.
     *
     * @return object{external_id?: string|null, customer_name?: string|null, address_text?: string|null, address_reference?: string|null}|null
     */
    private function shipmentRow(string $shipmentId): ?object
    {
        try {
            /** @var object{external_id?: string|null, customer_name?: string|null, address_text?: string|null, address_reference?: string|null}|null $row */
            $row = $this->db->connection()
                ->table('shipments')
                ->where('id', $shipmentId)
                ->first(['external_id', 'customer_name', 'address_text', 'address_reference']);

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lee la fila de tracking del envío (paradas restantes, ETA). Null si no existe o la consulta falla.
     *
     * @return object{remaining_stops?: int|string|null, eta?: string|null}|null
     */
    private function trackingRow(string $shipmentId): ?object
    {
        try {
            /** @var object{remaining_stops?: int|string|null, eta?: string|null}|null $row */
            $row = $this->db->connection()
                ->table('tracking_shipments')
                ->where('shipment_id', $shipmentId)
                ->first(['remaining_stops', 'eta']);

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Compone la dirección legible: texto principal y, si hay referencia, la agrega entre paréntesis.
     */
    private function composeAddress(?string $text, ?string $reference): ?string
    {
        if ($text === null) {
            return $reference;
        }
        if ($reference === null) {
            return $text;
        }

        return "{$text} ({$reference})";
    }

    /**
     * Formatea la ETA a un texto legible. Tolera valores nulos, timestamps de base y strings sueltos:
     * si no se puede interpretar como fecha, devuelve el valor crudo; si está vacío, null.
     */
    private function formatEta(?string $eta): ?string
    {
        if ($eta === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($eta))->format($this->etaFormat);
        } catch (Throwable) {
            return $eta;
        }
    }

    /**
     * Normaliza un valor a string no vacío (trim) o null.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
