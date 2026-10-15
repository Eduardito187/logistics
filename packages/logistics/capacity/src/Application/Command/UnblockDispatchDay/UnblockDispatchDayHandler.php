<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\UnblockDispatchDay;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Desbloquea un día de despacho: carga el bloqueo por su clave natural (fecha+municipio), graba el hecho
 * de desbloqueo al outbox y da de baja la fila. Lanza si no había bloqueo para esa clave.
 *
 * El remove y el append al outbox corren en la misma transacción (decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<UnblockDispatchDayCommand>
 */
final readonly class UnblockDispatchDayHandler implements CommandHandler
{
    public function __construct(
        private DispatchBlackoutRepository $blackouts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UnblockDispatchDayCommand);

        $date = SlotDate::fromString($command->date);

        $day = $this->blackouts->findByDate($date, $command->municipalityId)
            ?? throw CapacityException::because('no existe un bloqueo para esa fecha y municipio');

        $day->unblock();

        $this->blackouts->remove($day);

        $this->recordEvents->record($day->releaseEvents(), 'capacity.blackout_day');
    }
}
