<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Implementación del puerto de reserva de cupo por MUNICIPIO apoyada en el contexto de capacidad.
 *
 * Reservar consume UNA entrega del cupo del municipio para esa fecha y franja; liberar la devuelve. El
 * descuento lo hace el comando de capacity (ConsumeCapacity con municipalityId) con lock pesimista,
 * dentro de la MISMA transacción que abrió el CommandBus para la reserva: así cupo y franja del envío se
 * confirman o se deshacen juntos, y dos reservas simultáneas de la misma franja no sobre-reservan.
 *
 * reserve() traduce el agotamiento (o la inexistencia del cupo) a `false` para que el dominio decida. El
 * día no despachable lo valida el handler ANTES de llamar aquí, de modo que un `false` ya significa, con
 * certeza, cupo agotado; aun así se captura CapacityException por defensa (el guard de ConsumeCapacity
 * también rechaza el día bloqueado/fuera de horizonte) y se devuelve false sin romper la transacción.
 *
 * El cruce es por el bus y por primitivos; no se importa infraestructura de capacity.
 */
final readonly class CapacityBackedMunicipalitySlotReserver implements MunicipalitySlotReserver
{
    /** Cada reserva de promesa toma una unidad de entrega del cupo. */
    private const float ONE_DELIVERY = 1.0;

    public function __construct(private CommandBus $commandBus) {}

    public function reserve(string $municipalityId, DeliverySlot $slot): bool
    {
        try {
            $this->commandBus->dispatch(new ConsumeCapacityCommand(
                nodeId: null,
                zoneId: null,
                date: $slot->dateString(),
                timeSlot: $slot->timeSlot,
                unit: CapacityUnit::Deliveries->value,
                amount: self::ONE_DELIVERY,
                municipalityId: $municipalityId,
            ));

            return true;
        } catch (CapacityException) {
            // Cupo agotado o inexistente para ese municipio/fecha/franja (o día no despachable): no hay
            // reserva. El savepoint del dispatch anidado ya revirtió cualquier cambio parcial.
            return false;
        }
    }

    public function release(string $municipalityId, DeliverySlot $slot): void
    {
        try {
            $this->commandBus->dispatch(new ReleaseCapacityCommand(
                nodeId: null,
                zoneId: null,
                date: $slot->dateString(),
                timeSlot: $slot->timeSlot,
                unit: CapacityUnit::Deliveries->value,
                amount: self::ONE_DELIVERY,
                municipalityId: $municipalityId,
            ));
        } catch (CapacityException) {
            // Liberar es best-effort: si el cupo ya no existe, no hay nada que devolver.
        }
    }
}
