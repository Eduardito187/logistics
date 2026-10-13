<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\DefineCapacity;

use Logistics\Capacity\Domain\CapacityBucket;
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
 * Define un cupo: construye el agregado, lo persiste y libera sus eventos. Rechaza duplicar la clave
 * natural (scope/fecha/franja/unidad).
 *
 * @implements CommandHandler<DefineCapacityCommand>
 */
final readonly class DefineCapacityHandler implements CommandHandler
{
    public function __construct(private CapacityRepository $buckets) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineCapacityCommand);

        $scope = BucketScope::fromNullable($command->nodeId, $command->zoneId);
        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $unit = CapacityUnit::tryFrom($command->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$command->unit}");

        if ($this->buckets->findBucket($scope, $date, $slot, $unit) !== null) {
            throw CapacityException::because('ya existe un cupo para ese scope, fecha, franja y unidad');
        }

        $bucket = CapacityBucket::define(
            $this->buckets->nextIdentity(),
            $scope,
            $date,
            $slot,
            $unit,
            Quantity::of($command->total),
        );

        $this->buckets->save($bucket);

        // TODO(outbox): publicar estos eventos dentro de la misma transacción vía el servicio de
        // outbox (ADR 002). Por ahora solo se liberan del agregado; no se depende del package outbox.
        $bucket->releaseEvents();
    }
}
