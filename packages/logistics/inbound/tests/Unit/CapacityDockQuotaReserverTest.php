<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Infrastructure\Capacity\CapacityDockQuotaReserver;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Doble del CommandBus que captura el comando despachado, para verificar que el adaptador arma el eje
 * inbound de capacity (warehouseId presente, los tres ejes de despacho en null).
 */
function inboundCapturingBus(): CommandBus
{
    return new class implements CommandBus
    {
        public ?Command $dispatched = null;

        public function dispatch(Command $command): void
        {
            $this->dispatched = $command;
        }
    };
}

it('consume el cupo de andén por el eje inbound (warehouse + día + franja, una cita)', function (): void {
    $bus = inboundCapturingBus();
    $reserver = new CapacityDockQuotaReserver($bus);

    $reserver->reserve('01931001-0000-7000-8000-0000000000f1', AppointmentWindow::of('2026-10-20', '08:00', '12:00'));

    $command = $bus->dispatched;
    expect($command)->toBeInstanceOf(ConsumeCapacityCommand::class)
        ->and($command->warehouseId)->toBe('01931001-0000-7000-8000-0000000000f1')
        ->and($command->nodeId)->toBeNull()
        ->and($command->zoneId)->toBeNull()
        ->and($command->municipalityId)->toBeNull()
        ->and($command->date)->toBe('2026-10-20')
        ->and($command->timeSlot)->toBe('08:00-12:00')
        ->and($command->unit)->toBe('appointments')
        ->and($command->amount)->toBe(1.0);
});

it('respeta la unidad y la cantidad por cita configuradas', function (): void {
    $bus = inboundCapturingBus();
    $reserver = new CapacityDockQuotaReserver($bus, 'pallets', 2.5);

    $reserver->reserve('wh-1', AppointmentWindow::of('2026-10-21', '14:00', '18:00'));

    $command = $bus->dispatched;
    expect($command)->toBeInstanceOf(ConsumeCapacityCommand::class)
        ->and($command->unit)->toBe('pallets')
        ->and($command->amount)->toBe(2.5);
});
