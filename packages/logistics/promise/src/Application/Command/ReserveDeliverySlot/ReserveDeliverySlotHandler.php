<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveDeliverySlot;

use DateTimeImmutable;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Orquesta la reserva de una franja de despacho para un pedido: descuenta una entrega del cupo de la
 * zona (capacity) y fija la franja en el envío (shipments). Todo dentro de la misma transacción del
 * CommandBus, así los dos efectos son atómicos: si algo falla, no queda ni cupo tomado ni franja puesta.
 *
 * Idempotente: si el pedido ya tiene EXACTAMENTE esa franja, no vuelve a consumir cupo ni a emitir
 * cambios (reservar dos veces el mismo slot es inocuo). Si el pedido tenía OTRA franja, primero devuelve
 * el cupo viejo y luego toma el nuevo (reprogramación dentro de la misma zona).
 *
 * El cruce a capacity y a shipments es por puertos (SlotReserver, OrderDeliverySlotGateway) y por el
 * bus; este handler es PHP puro y no conoce esos contextos ni el framework.
 *
 * @implements CommandHandler<ReserveDeliverySlot>
 */
final readonly class ReserveDeliverySlotHandler implements CommandHandler
{
    public function __construct(
        private SlotReserver $slotReserver,
        private OrderDeliverySlotGateway $orders,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReserveDeliverySlot);

        $requestedSlot = $this->slotFrom($command->date, $command->windowStart, $command->windowEnd);

        $current = $this->orders->currentSlot($command->orderId);
        if ($this->isSameSlot($current, $command)) {
            // El pedido ya tiene esa franja: nada que reservar ni cambiar.
            return;
        }

        // Reprogramación: devuelve el cupo de la franja anterior antes de tomar la nueva. Como todo
        // corre en una sola transacción, si la toma del nuevo cupo falla, esta devolución se revierte.
        if ($current !== null) {
            $this->slotReserver->release(
                $command->zoneId,
                $this->slotFrom($current['date'], $current['window_start'], $current['window_end']),
            );
        }

        if (!$this->slotReserver->reserve($command->zoneId, $requestedSlot)) {
            throw PromiseException::because('no se pudo reservar la franja: capacidad agotada');
        }

        $this->orders->assignSlot($command->orderId, $command->date, $command->windowStart, $command->windowEnd);
    }

    /**
     * @param array{date: string, window_start: string, window_end: string}|null $current
     */
    private function isSameSlot(?array $current, ReserveDeliverySlot $command): bool
    {
        return $current !== null
            && $current['date'] === $command->date
            && $current['window_start'] === $command->windowStart
            && $current['window_end'] === $command->windowEnd;
    }

    /**
     * Construye el slot de reserva de capacity desde primitivos. La franja viaja a capacity como la
     * etiqueta 'HH:MM-HH:MM' (el formato de TimeSlot de capacity).
     */
    private function slotFrom(string $date, string $windowStart, string $windowEnd): DeliverySlot
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false) {
            throw PromiseException::because("fecha inválida (se esperaba Y-m-d): {$date}");
        }

        return DeliverySlot::of($day, "{$windowStart}-{$windowEnd}");
    }
}
