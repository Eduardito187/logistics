<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterHelper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el ayudante, lo persiste y publica sus eventos al outbox.
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
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterHelper);

        $documents = array_map(
            static fn (array $doc): DocumentExpiry => DocumentExpiry::of(
                $doc['type'],
                new DateTimeImmutable($doc['expires_on']),
            ),
            $command->documents,
        );

        $helper = Helper::register(
            $this->helpers->nextIdentity(),
            $command->name,
            $command->phone,
            array_values($documents),
            $command->active,
        );

        $this->helpers->save($helper);

        $this->recordEvents->record($helper->releaseEvents(), 'fleet.helper');
    }
}
