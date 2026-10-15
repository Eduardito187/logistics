<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RecordAuditEntry;

use Logistics\Access\Domain\Contract\AuditLogRepository;
use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Access\Domain\ValueObject\AuditAction;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Construye la entrada de auditoría con la hora del reloj, la agrega a la bitácora (append-only) y publica
 * su evento al outbox. Falla si la acción no respeta el formato "sujeto.verbo".
 *
 * El now() llega por el puerto {@see Clock} (nunca el helper global), para que los tests fijen el tiempo.
 * El append del repositorio y el append al outbox corren dentro de la misma transacción que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RecordAuditEntry>
 */
final readonly class RecordAuditEntryHandler implements CommandHandler
{
    public function __construct(
        private AuditLogRepository $log,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordAuditEntry);

        $entry = AuditEntry::record(
            $this->log->nextIdentity(),
            $command->actorId,
            $command->actorLabel,
            AuditAction::fromString($command->action),
            $command->subjectType,
            $command->subjectId,
            $command->context,
            $command->ipAddress,
            $this->clock->now(),
        );

        $this->log->append($entry);

        $this->recordEvents->record($entry->releaseEvents(), 'access.audit');
    }
}
