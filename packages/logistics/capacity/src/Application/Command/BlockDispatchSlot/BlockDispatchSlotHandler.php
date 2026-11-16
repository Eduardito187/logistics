<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\BlockDispatchSlot;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\DispatchBlackoutSlot;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Bloquea una franja horaria de despacho: construye (o reaprovecha, por su clave natural
 * fecha+franja+municipio) el agregado, lo persiste y publica sus eventos al outbox. Idempotente:
 * re-bloquear la misma franja no duplica filas; actualiza el motivo y vuelve a emitir el hecho.
 *
 * El save y el append al outbox corren en la misma transacción (decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<BlockDispatchSlotCommand>
 */
final readonly class BlockDispatchSlotHandler implements CommandHandler
{
    public function __construct(
        private DispatchBlackoutSlotRepository $slots,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof BlockDispatchSlotCommand);

        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $municipalityId = $command->municipalityId;

        // Reusa el id existente si la fecha+franja+municipio ya estaba bloqueada (idempotencia por clave
        // natural): no duplica la fila, solo refresca el motivo.
        $existing = $this->slots->findByKey($date, $slot, $municipalityId);
        $id = $existing?->id() ?? $this->slots->nextIdentity();

        $blackout = DispatchBlackoutSlot::block($id, $date, $slot, $municipalityId, $command->reason, $command->blockedBy);

        $this->slots->save($blackout);

        $this->recordEvents->record($blackout->releaseEvents(), 'capacity.blackout_slot');
    }
}
