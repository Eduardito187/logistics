<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ReleaseCapacity;

use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Libera capacidad: carga el bucket por su clave natural, devuelve la cantidad (acotada a 0),
 * persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ReleaseCapacityCommand>
 */
final readonly class ReleaseCapacityHandler implements CommandHandler
{
    public function __construct(
        private CapacityRepository $buckets,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReleaseCapacityCommand);

        // El eje inbound (recepción) se detecta por la presencia del almacén; si no, es un cupo de
        // despacho con exactamente uno de nodo/zona/municipio.
        $scope = $command->warehouseId !== null
            ? BucketScope::forReceivingDock($command->warehouseId, $command->dockId)
            : BucketScope::fromNullable($command->nodeId, $command->zoneId, $command->municipalityId);
        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $unit = CapacityUnit::tryFrom($command->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$command->unit}");

        // Lock pesimista: liberar también muta `used`, así que se serializa con el consumo concurrente
        // del mismo cupo para que la cuenta no se corrompa bajo carrera.
        $bucket = $this->buckets->findBucketForUpdate($scope, $date, $slot, $unit)
            ?? throw CapacityException::because('no existe un cupo para ese scope, fecha, franja y unidad');

        $bucket->release(Quantity::of($command->amount));

        $this->buckets->save($bucket);

        $this->recordEvents->record($bucket->releaseEvents(), 'capacity.bucket');
    }
}
