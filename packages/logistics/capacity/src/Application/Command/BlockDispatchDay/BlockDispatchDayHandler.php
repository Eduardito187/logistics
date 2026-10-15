<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\BlockDispatchDay;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Bloquea un día de despacho: construye (o reaprovecha, por su clave natural fecha+municipio) el
 * agregado, lo persiste y publica sus eventos al outbox. Idempotente: re-bloquear la misma fecha y
 * municipio no duplica filas; actualiza el motivo y vuelve a emitir el hecho.
 *
 * El save y el append al outbox corren en la misma transacción (decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<BlockDispatchDayCommand>
 */
final readonly class BlockDispatchDayHandler implements CommandHandler
{
    public function __construct(
        private DispatchBlackoutRepository $blackouts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof BlockDispatchDayCommand);

        $date = SlotDate::fromString($command->date);
        $municipalityId = $command->municipalityId;

        // Reusa el id existente si la fecha+municipio ya estaba bloqueada (idempotencia por clave natural).
        $existing = $this->blackouts->findByDate($date, $municipalityId);
        $id = $existing?->id() ?? $this->blackouts->nextIdentity();

        $day = DispatchBlackoutDay::block($id, $date, $municipalityId, $command->reason, $command->blockedBy);

        $this->blackouts->save($day);

        $this->recordEvents->record($day->releaseEvents(), 'capacity.blackout_day');
    }
}
