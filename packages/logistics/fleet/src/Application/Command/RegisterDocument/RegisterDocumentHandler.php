<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterDocument;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el documento de flota, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterDocument>
 */
final readonly class RegisterDocumentHandler implements CommandHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterDocument);

        $document = FleetDocument::register(
            $this->documents->nextIdentity(),
            DocumentOwnerType::from($command->ownerType),
            $command->ownerId,
            DocumentExpiry::of($command->documentType, new DateTimeImmutable($command->expiresOn)),
            $command->issuedOn !== null ? new DateTimeImmutable($command->issuedOn) : null,
            $command->filePath,
            $command->note,
        );

        $this->documents->save($document);

        $this->recordEvents->record($document->releaseEvents(), 'fleet.document');
    }
}
