<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\UnblockDispatchSlot;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita el bloqueo de una franja horaria de despacho: carga el bloqueo por su clave natural
 * (fecha+franja+municipio); si no existe, no hace nada (idempotente, como pide el DELETE). Si existe,
 * graba el hecho de desbloqueo al outbox y da de baja la fila.
 *
 * El remove y el append al outbox corren en la misma transacción (decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<UnblockDispatchSlotCommand>
 */
final readonly class UnblockDispatchSlotHandler implements CommandHandler
{
    public function __construct(
        private DispatchBlackoutSlotRepository $slots,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UnblockDispatchSlotCommand);

        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);

        $blackout = $this->slots->findByKey($date, $slot, $command->municipalityId);
        if ($blackout === null) {
            // No había bloqueo para esa clave: nada que quitar. El DELETE es idempotente.
            return;
        }

        $blackout->unblock();

        $this->slots->remove($blackout);

        $this->recordEvents->record($blackout->releaseEvents(), 'capacity.blackout_slot');
    }
}
