<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Fleet\Domain\Contract\CrewRoster;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Infrastructure\Persistence\CrewHistoryWriter;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\CrewMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;

/**
 * Planilla del día sobre PostgreSQL: bloqueo por fecha, ocupante del camión, liberación de ayudantes y
 * baja con rastro. `fleet_crews` guarda solo lo vigente; lo que queda sin efecto pasa a
 * `fleet_crew_history` (ver CrewHistoryWriter).
 */
final readonly class EloquentCrewRoster implements CrewRoster
{
    public function __construct(
        private ConnectionInterface $db,
        private CrewMapper $mapper = new CrewMapper,
    ) {}

    public function lockDay(DateTimeImmutable $date): void
    {
        // Candado de aviso a nivel transacción: se suelta solo al confirmar o deshacer. Dos armados de la
        // misma fecha se atienden de a uno, así el segundo ya ve lo que dejó el primero. Fuera de una
        // transacción no retiene nada; ahí la defensa es el índice único de la tabla.
        // Siempre por la conexión de escritura: un candado tomado en una réplica no protege nada.
        $this->db->select('select pg_advisory_xact_lock(hashtext(?))', ['fleet_crews:'.$date->format('Y-m-d')], false);
    }

    public function findForVehicleOn(VehicleId $vehicleId, DateTimeImmutable $date): ?Crew
    {
        $model = CrewModel::query()
            ->where('vehicle_id', $vehicleId->toString())
            ->where('assembled_for', $date->format('Y-m-d'))
            ->orderByRaw('created_at desc nulls last')
            ->orderByDesc('id')
            ->first();

        return $model instanceof CrewModel ? $this->mapper->toDomain($model) : null;
    }

    public function releaseHelpersOn(array $helperIds, DateTimeImmutable $date): void
    {
        $helperIds = array_values(array_unique($helperIds));
        if ($helperIds === []) {
            return;
        }

        $crews = CrewModel::query()->where('assembled_for', $date->format('Y-m-d'))->lockForUpdate()->get();

        foreach ($crews as $crew) {
            $kept = array_values(array_diff($crew->helper_ids, $helperIds));
            if (count($kept) !== count($crew->helper_ids)) {
                $crew->helper_ids = $kept;
                $crew->save();
            }
        }
    }

    public function retire(Crew $crew, CrewRetirementReason $reason, ?Crew $successor = null): void
    {
        (new CrewHistoryWriter($this->db))->retire(
            $crew->id()->toString(),
            $reason->value,
            $successor?->id()->toString(),
            $successor?->vehicleId()->toString(),
        );
    }
}
