<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RegisterCarrier;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el transportista, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterCarrier>
 */
final readonly class RegisterCarrierHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterCarrier);

        $carrier = Carrier::register(
            $this->carriers->nextIdentity(),
            $command->name,
            $command->taxId,
            $command->documents,
        );

        $this->carriers->save($carrier);

        $this->recordEvents->record($carrier->releaseEvents(), 'carriers.carrier');
    }
}
