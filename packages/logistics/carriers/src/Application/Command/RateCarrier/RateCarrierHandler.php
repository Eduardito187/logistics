<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RateCarrier;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\Rating;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el transportista, agrega la calificación (recalcula el promedio), lo persiste y publica sus
 * eventos al outbox. Falla si no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RateCarrier>
 */
final readonly class RateCarrierHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RateCarrier);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }

        $carrier->addRating(Rating::of($command->rating));

        $this->carriers->save($carrier);

        $this->recordEvents->record($carrier->releaseEvents(), 'carriers.carrier');
    }
}
