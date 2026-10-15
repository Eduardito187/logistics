<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ConsumeCapacity;

use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\Service\DispatchDayAvailability;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Consume capacidad: valida que el día sea despachable, carga el bucket por su clave natural, aplica el
 * consumo (que lanza si excede), persiste y publica sus eventos al outbox.
 *
 * Enforcement end-to-end (frente de calendario): antes de imputar capacidad RECHAZA
 *  - un día bloqueado (feriado u otro motivo), global o del municipio del cupo; y
 *  - una fecha fuera del horizonte activo del alcance, cuando hay un horizonte registrado.
 * Si no hay horizonte registrado para el alcance, no se exige (compatibilidad con cupos definidos a
 * mano antes de activar por rango).
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
        private DispatchDayAvailability $availability,
        private ActiveHorizonRepository $horizons,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ConsumeCapacityCommand);

        $scope = BucketScope::fromNullable($command->nodeId, $command->zoneId, $command->municipalityId);
        $date = SlotDate::fromString($command->date);
        $slot = TimeSlot::fromString($command->timeSlot);
        $unit = CapacityUnit::tryFrom($command->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$command->unit}");

        $this->guardDispatchable($scope, $date);

        // Lock pesimista: serializa el consumo concurrente del mismo cupo para no sobre-reservarlo. El
        // lock corre dentro de la transacción que abre el CommandBus y se suelta al cerrarla.
        $bucket = $this->buckets->findBucketForUpdate($scope, $date, $slot, $unit)
            ?? throw CapacityException::because('no existe un cupo para ese scope, fecha, franja y unidad');

        $bucket->consume(Quantity::of($command->amount));

        $this->buckets->save($bucket);

        $this->recordEvents->record($bucket->releaseEvents(), 'capacity.bucket');
    }

    /**
     * Rechaza consumir capacidad en un día no despachable: bloqueado (feriado/otro motivo) o fuera del
     * horizonte activo del alcance. El horizonte solo se exige si hay uno registrado para el alcance.
     */
    private function guardDispatchable(BucketScope $scope, SlotDate $date): void
    {
        $municipalityId = $scope->municipalityId;

        if ($this->availability->isBlocked($date, $municipalityId)) {
            throw CapacityException::because("el día {$date->toString()} está bloqueado para despacho (feriado u otro motivo); no se puede consumir capacidad");
        }

        $horizon = $this->horizons->findFor($municipalityId);
        if ($horizon !== null && !$horizon->covers($date)) {
            throw CapacityException::because("la fecha {$date->toString()} está fuera del horizonte de cupos activo (hasta {$horizon->activeUntil()->toString()})");
        }
    }
}
