<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\AttachSettlementDocument;

use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la liquidación, le adjunta el documento, la persiste y publica sus eventos al outbox.
 * Falla si no existe.
 *
 * @implements CommandHandler<AttachSettlementDocument>
 */
final readonly class AttachSettlementDocumentHandler implements CommandHandler
{
    public function __construct(
        private SettlementRepository $settlements,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AttachSettlementDocument);

        $settlement = $this->settlements->findById(SettlementId::fromString($command->settlementId));
        if ($settlement === null) {
            throw CarriersException::because("No existe la liquidación {$command->settlementId}.");
        }

        $settlement->attachDocument([
            'type' => $command->documentType,
            'reference' => $command->documentReference,
        ]);

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'carriers.settlement');
    }
}
