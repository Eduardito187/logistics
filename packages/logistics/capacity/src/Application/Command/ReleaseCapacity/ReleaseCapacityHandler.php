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
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Libera capacidad: carga el bucket por su clave natural, devuelve la cantidad (acotada a 0),
 * persiste y libera sus eventos.
 *
 * @implements CommandHandler<ReleaseCapacityCommand>
 */
final readonly class ReleaseCapacityHandler implements CommandHandler
{
    public function __construct(private CapacityRepository $buckets) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReleaseCapacityCommand);

        $scope = BucketScope::fromNullable($command->nodeId, $command->zoneId);
        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $unit = CapacityUnit::tryFrom($command->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$command->unit}");

        $bucket = $this->buckets->findBucket($scope, $date, $slot, $unit)
            ?? throw CapacityException::because('no existe un cupo para ese scope, fecha, franja y unidad');

        $bucket->release(Quantity::of($command->amount));

        $this->buckets->save($bucket);

        // TODO(outbox): publicar estos eventos dentro de la misma transacción vía el servicio de
        // outbox (ADR 002). Por ahora solo se liberan del agregado; no se depende del package outbox.
        $bucket->releaseEvents();
    }
}
