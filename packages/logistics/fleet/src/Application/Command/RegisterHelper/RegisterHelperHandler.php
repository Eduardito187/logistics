<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterHelper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el ayudante, lo persiste y publica sus eventos al outbox.
 *
 * Los documentos que llegan al registrar se persisten en la tabla polimórfica `fleet_documents`
 * (owner_type='helper'), NO en el jsonb inline del agregado (A5): así los documentos del ayudante viven
 * en un único lugar —el mismo store rico que usan vehículo y chofer— y las alertas de vencimiento los
 * leen una sola vez (sin doble conteo). El agregado nace sin documentos inline.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterHelper>
 */
final readonly class RegisterHelperHandler implements CommandHandler
{
    public function __construct(
        private HelperRepository $helpers,
        private DocumentRepository $documents,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterHelper);

        $helperId = $this->helpers->nextIdentity();

        // El agregado nace SIN documentos inline: los documentos se guardan en fleet_documents (abajo).
        $helper = Helper::register(
            $helperId,
            $command->name,
            $command->phone,
            [],
            $command->active,
        );

        $this->helpers->save($helper);
        $this->recordEvents->record($helper->releaseEvents(), 'fleet.helper');

        // Cada documento del alta → fila en fleet_documents (dueño polimórfico helper), con sus eventos.
        foreach ($command->documents as $doc) {
            $document = FleetDocument::register(
                $this->documents->nextIdentity(),
                DocumentOwnerType::Helper,
                $helperId->toString(),
                DocumentExpiry::of($doc['type'], new DateTimeImmutable($doc['expires_on'])),
            );

            $this->documents->save($document);
            $this->recordEvents->record($document->releaseEvents(), 'fleet.document');
        }
    }
}
