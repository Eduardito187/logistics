<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SetTariff;

use DateTimeImmutable;
use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\Tariff;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define una tarifa para un transportista existente, la persiste y publica sus eventos al outbox.
 * Falla si el transportista no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetTariff>
 */
final readonly class SetTariffHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
        private TariffRepository $tariffs,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetTariff);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }

        $tariff = Tariff::define(
            $this->tariffs->nextIdentity(),
            $command->carrierId,
            CorridorRef::of($command->corridorFrom, $command->corridorTo, $command->corridorType),
            VehicleClass::from($command->vehicleClass),
            Money::of($command->priceCents, $command->currency),
            new DateTimeImmutable($command->effectiveFrom),
        );

        $this->tariffs->save($tariff);

        $this->recordEvents->record($tariff->releaseEvents(), 'carriers.tariff');
    }
}
