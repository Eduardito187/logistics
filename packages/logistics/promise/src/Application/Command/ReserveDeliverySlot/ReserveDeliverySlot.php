<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveDeliverySlot;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención del canal de reservar una franja de despacho para un pedido concreto: toma el cupo de la
 * zona para esa fecha y ventana (en capacity) y fija la franja en el envío (en shipments), de forma
 * atómica e idempotente.
 *
 * DTO inmutable de primitivos. La zona es la del pedido; la fecha es 'Y-m-d' y la ventana es
 * inicio/fin en 'HH:MM'.
 */
final readonly class ReserveDeliverySlot implements Command
{
    public function __construct(
        public string $orderId,
        public string $zoneId,
        public string $date,
        public string $windowStart,
        public string $windowEnd,
    ) {}
}
