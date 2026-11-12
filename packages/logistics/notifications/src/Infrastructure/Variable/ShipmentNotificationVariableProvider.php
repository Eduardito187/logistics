<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Variable;

use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Notifications\Domain\Contract\NotificationVariableProvider;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Throwable;

/**
 * Implementación del proveedor de variables: arma el mapa de interpolación desde los datos reales del
 * envío y del tracking, leyendo por nombre de tabla con el query builder (no acopla contra los modelos
 * Eloquent de otros packages).
 *
 * - {{order}}      ← shipments.external_id (referencia humana del pedido); si falta, la referencia de la
 *                    orden. NUNCA el UUID interno del envío (si no hay referencia humana, la clave se omite).
 * - {{order_reference}} ← shipments.order_reference, o tracking_shipments.order_reference, o la parte de
 *                    external_id antes de '#n:' (multishipping). Se omite si no hay referencia humana.
 * - {{customer}}   ← shipments.customer_name.
 * - {{address}}    ← shipments.address_text (+ address_reference entre paréntesis si existe).
 * - {{stops}}      ← tracking_shipments.remaining_stops (paradas que faltan antes de la entrega).
 * - {{eta}}        ← tracking_shipments.eta (hora estimada, formateada legible).
 * - {{tracking_url}} ← URL ABSOLUTA de la página pública de seguimiento (/t/{token}, ruta
 *                    `tracking.public.page`). El token opaco solo aparece DENTRO de la URL.
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
        private ?UrlGenerator $urls = null,
    ) {}

    public function variablesFor(string $shipmentId, MessageType $messageType): array
    {
        $variables = [];

        $shipment = $this->shipmentRow($shipmentId);
        $tracking = $this->trackingRow($shipmentId);

        $orderReference = $this->humanReference($shipment, $tracking);
        if ($orderReference !== null) {
            $variables['order_reference'] = $orderReference;
        }

        if ($shipment !== null) {
            // {{order}}: referencia humana del pedido; si falta, la referencia de la orden. Nunca un UUID
            // (texto de cara al cliente): se corta el sufijo de retiro '#n:{nodo}' que lleva el id del nodo.
            $rawExternalId = $this->stringOrNull($shipment->external_id ?? null);
            $externalId = $rawExternalId !== null
                ? $this->notUuid($this->stringOrNull(explode('#n:', $rawExternalId, 2)[0]))
                : null;
            $order = $externalId ?? $orderReference;
            if ($order !== null) {
                $variables['order'] = $order;
            }

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

        if ($tracking !== null) {
            if (isset($tracking->remaining_stops) && is_numeric($tracking->remaining_stops)) {
                $variables['stops'] = (string) max(0, (int) $tracking->remaining_stops);
            }

            $eta = $this->formatEta($this->stringOrNull($tracking->eta ?? null));
            if ($eta !== null) {
                $variables['eta'] = $eta;
            }

            $token = $this->stringOrNull($tracking->token ?? null);
            $url = $token !== null ? $this->trackingUrl($token) : null;
            if ($url !== null) {
                $variables['tracking_url'] = $url;
            }
        }

        return $variables;
    }

    /**
     * Referencia humana de la orden (agrupa los envíos del multishipping): la del envío, la del
     * seguimiento o la parte de external_id antes de '#n:'. Null si no hay ninguna que no sea un UUID.
     *
     * @param object{external_id?: string|null, order_reference?: string|null}|null $shipment
     * @param object{order_reference?: string|null}|null                            $tracking
     */
    private function humanReference(?object $shipment, ?object $tracking): ?string
    {
        $candidates = [
            $shipment?->order_reference ?? null,
            $tracking?->order_reference ?? null,
        ];

        $externalId = $this->stringOrNull($shipment?->external_id ?? null);
        if ($externalId !== null) {
            $candidates[] = explode('#n:', $externalId, 2)[0];
        }

        foreach ($candidates as $candidate) {
            $value = $this->notUuid($this->stringOrNull($candidate));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * URL absoluta de la página pública de seguimiento para el token. Usa la ruta con nombre del contexto
     * tracking; si no está registrada (contexto no cargado), arma la ruta conocida `/t/{token}`. La base
     * sale de APP_URL en workers/colas. Null si no se puede generar.
     */
    private function trackingUrl(string $token): ?string
    {
        try {
            $urls = $this->urls ?? Container::getInstance()->make(UrlGenerator::class);
        } catch (Throwable) {
            return null;
        }

        try {
            return $urls->route('tracking.public.page', ['token' => $token], true);
        } catch (Throwable) {
            try {
                return $urls->to('/t/'.rawurlencode($token));
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Descarta valores con forma de UUID: nunca se muestran al cliente (regla del dueño).
     */
    private function notUuid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
            ? null
            : $value;
    }

    /**
     * Lee la fila del envío (referencia, cliente, dirección). Null si no existe o la consulta falla.
     *
     * @return object{external_id?: string|null, order_reference?: string|null, customer_name?: string|null, address_text?: string|null, address_reference?: string|null}|null
     */
    private function shipmentRow(string $shipmentId): ?object
    {
        try {
            /** @var object{external_id?: string|null, order_reference?: string|null, customer_name?: string|null, address_text?: string|null, address_reference?: string|null}|null $row */
            $row = $this->db->connection()
                ->table('shipments')
                ->where('id', $shipmentId)
                ->first(['external_id', 'order_reference', 'customer_name', 'address_text', 'address_reference']);

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lee la fila de tracking del envío (paradas restantes, ETA, token público, referencia de la orden).
     * Null si no existe o la consulta falla.
     *
     * @return object{remaining_stops?: int|string|null, eta?: string|null, token?: string|null, order_reference?: string|null}|null
     */
    private function trackingRow(string $shipmentId): ?object
    {
        try {
            /** @var object{remaining_stops?: int|string|null, eta?: string|null, token?: string|null, order_reference?: string|null}|null $row */
            $row = $this->db->connection()
                ->table('tracking_shipments')
                ->where('shipment_id', $shipmentId)
                ->first(['remaining_stops', 'eta', 'token', 'order_reference']);

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
