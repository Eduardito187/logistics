<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ConsumeCapacity;

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
 * Consume capacidad: carga el bucket por su clave natural, aplica el consumo (que lanza si excede),
 * persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ConsumeCapacityCommand>
 */
final readonly class ConsumeCapacityHandler implements CommandHandler
{
    public function __construct(
        private CapacityRepository $buckets,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ConsumeCapacityCommand);

        $scope = BucketScope::fromNullable($command->nodeId, $command->zoneId);
        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $unit = CapacityUnit::tryFrom($command->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$command->unit}");

        // Lock pesimista: serializa el consumo concurrente del mismo cupo para no sobre-reservarlo. El
        // lock corre dentro de la transacción que abre el CommandBus y se suelta al cerrarla.
        $bucket = $this->buckets->findBucketForUpdate($scope, $date, $slot, $unit)
            ?? throw CapacityException::because('no existe un cupo para ese scope, fecha, franja y unidad');

        $bucket->consume(Quantity::of($command->amount));

        $this->buckets->save($bucket);

        $this->recordEvents->record($bucket->releaseEvents(), 'capacity.bucket');
    }
}
