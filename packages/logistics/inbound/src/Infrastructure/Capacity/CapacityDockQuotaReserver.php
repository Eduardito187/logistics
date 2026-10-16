<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Capacity;

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Adaptador por defecto del puerto DockQuotaReserver: consume un cupo de ANDÉN DE RECEPCIÓN del contexto
 * `capacity` por el `CommandBus`, usando el EJE INBOUND que capacity expone (clave natural almacén + día +
 * franja, unidad `appointments`). Una cita por reserva (`amount = 1`).
 *
 * Es el ÚNICO punto de inbound que conoce a capacity (y por eso vive en Infrastructure). Capacity detecta
 * el eje inbound por `warehouseId` (los tres ejes de despacho van en null) y NO aplica feriados/horizonte
 * de despacho: solo rige el tope del andén. Si capacity aún no tiene cupos definidos para ese almacén/día/
 * franja, `ConsumeCapacity` lanza su CapacityException y el agendamiento se revierte (misma transacción del
 * CommandBus). Para el flujo núcleo sin capacity, el ServiceProvider puede bindear un doble (ver tests).
 */
final readonly class CapacityDockQuotaReserver implements DockQuotaReserver
{
    public function __construct(
        private CommandBus $commands,
        private string $unit = 'appointments',
        private float $amountPerAppointment = 1.0,
    ) {}

    public function reserve(string $warehouseId, AppointmentWindow $window): void
    {
        $this->commands->dispatch(new ConsumeCapacityCommand(
            nodeId: null,
            zoneId: null,
            date: $window->date,
            timeSlot: $window->timeSlot(),
            unit: $this->unit,
            amount: $this->amountPerAppointment,
            warehouseId: $warehouseId,
        ));
    }
}
