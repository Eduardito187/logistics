<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\ScheduleCarrierTrip;

use DateTimeImmutable;
use Exception;
use Logistics\Carriers\Application\Service\ShipmentLoadGuard;
use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CarrierStatus;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Programa un viaje: valida que el transportista exista y esté activo, que los envíos existan y no estén
 * en otro viaje vigente; persiste y publica sus eventos al outbox (misma transacción, bus transaccional).
 *
 * @implements CommandHandler<ScheduleCarrierTrip>
 */
final readonly class ScheduleCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private CarrierRepository $carriers,
        private ShipmentLoadGuard $loadGuard,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ScheduleCarrierTrip);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }
        if ($carrier->status() !== CarrierStatus::Active) {
            throw CarriersException::because('No se puede programar un viaje para un transportista suspendido.');
        }

        try {
            $scheduledAt = new DateTimeImmutable($command->scheduledAt);
        } catch (Exception) {
            throw CarriersException::because("Fecha programada inválida: {$command->scheduledAt}.");
        }

        $id = $command->tripId !== null && $command->tripId !== ''
            ? CarrierTripId::fromString($command->tripId)
            : $this->trips->nextIdentity();

        $trip = CarrierTrip::schedule(
            $id,
            $carrier->id()->toString(),
            $command->corridorFrom,
            $command->corridorTo,
            $scheduledAt,
            $command->reference,
            $command->shipmentIds,
        );

        $this->loadGuard->assertLoadable($trip->shipmentIds(), $id);

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
