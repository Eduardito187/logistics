<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Hechos de ruta y seguimiento de un envío, tal como los lee la consulta de pedidos (solo primitivos).
 *
 *  - `orderReference`: referencia de la orden (agrupa los envíos de un mismo pedido);
 *  - `trackingStatus`: estado visible de la proyección de seguimiento (null si no tiene seguimiento);
 *  - `trackingOnRoute` / `trackingStopsAhead` / `trackingEta`: ubicación en ruta según la proyección de
 *    seguimiento (paradas pendientes que el camión tiene por delante de la de este envío);
 *  - `liveOnRoute` / `liveStopsAhead` / `liveEta`: lo mismo contado sobre la ruta real del chofer; es el
 *    respaldo cuando la proyección todavía no ubicó al envío.
 *
 * Las fechas van en ISO 8601 (ATOM).
 */
final readonly class OrderRouteFacts
{
    public function __construct(
        public ?string $orderReference = null,
        public ?string $trackingStatus = null,
        public bool $trackingOnRoute = false,
        public ?int $trackingStopsAhead = null,
        public ?string $trackingEta = null,
        public bool $liveOnRoute = false,
        public ?int $liveStopsAhead = null,
        public ?string $liveEta = null,
    ) {}

    /**
     * Paradas que faltan antes de la de este envío: manda la proyección de seguimiento; si todavía no lo
     * ubicó, la cuenta sobre la ruta real. Null si el envío no está en ninguna ruta.
     */
    public function stopsBefore(): ?int
    {
        if ($this->trackingOnRoute) {
            return $this->trackingStopsAhead;
        }

        return $this->liveOnRoute ? $this->liveStopsAhead : null;
    }

    /**
     * Hora estimada de llegada: la del seguimiento o, si no la tiene, la de su parada en la ruta.
     */
    public function eta(): ?string
    {
        return $this->trackingEta ?? $this->liveEta;
    }
}
