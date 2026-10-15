<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Implementación real del puerto de reserva de cupo apoyada en el contexto de capacidad.
 *
 * Reservar consume UNA entrega del cupo de la zona para esa fecha y franja; liberar la devuelve. El
 * descuento lo hace el comando de capacity con lock pesimista, dentro de la MISMA transacción que abrió
 * el CommandBus para la operación de promesa (el dispatch anidado anida un savepoint): así reserva de
 * cupo y cambio de la promesa se confirman o se deshacen juntos, y dos reservas simultáneas de la misma
 * franja no sobre-reservan.
 *
 * reserve() traduce el agotamiento (o la inexistencia del cupo) a `false` para que el dominio de promise
 * decida qué hacer, sin conocer a capacity. El cruce es por el bus y por primitivos; no se importa
 * infraestructura de capacity.
 */
final readonly class CapacityBackedSlotReserver implements SlotReserver
{
    /** Cada reserva de promesa toma una unidad de entrega del cupo. */
    private const float ONE_DELIVERY = 1.0;

    public function __construct(private CommandBus $commandBus) {}

    public function reserve(string $zoneId, DeliverySlot $slot): bool
    {
        try {
            $this->commandBus->dispatch(new ConsumeCapacityCommand(
                nodeId: null,
                zoneId: $zoneId,
                date: $slot->dateString(),
                timeSlot: $slot->timeSlot,
                unit: CapacityUnit::Deliveries->value,
                amount: self::ONE_DELIVERY,
            ));

            return true;
        } catch (CapacityException) {
            // Cupo agotado o inexistente para esa zona/fecha/franja: no hay reserva. El savepoint del
            // dispatch anidado ya revirtió cualquier cambio parcial; la transacción externa sigue viva.
            return false;
        }
    }

    public function release(string $zoneId, DeliverySlot $slot): void
    {
        try {
            $this->commandBus->dispatch(new ReleaseCapacityCommand(
                nodeId: null,
                zoneId: $zoneId,
                date: $slot->dateString(),
                timeSlot: $slot->timeSlot,
                unit: CapacityUnit::Deliveries->value,
                amount: self::ONE_DELIVERY,
            ));
        } catch (CapacityException) {
            // Liberar es best-effort: si el cupo ya no existe, no hay nada que devolver.
        }
    }
}
