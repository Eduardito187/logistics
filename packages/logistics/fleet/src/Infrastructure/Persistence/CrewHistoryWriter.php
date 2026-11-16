<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Deja sin efecto una tripulación conservando su rastro: copia la fila de `fleet_crews` a
 * `fleet_crew_history` (misma id, con el motivo y la tripulación que la reemplaza) y la saca de las
 * vigentes, en una sola transacción.
 *
 * Por qué se mueve y no se marca: `fleet_crews` la leen muchos contextos (planificación, envíos, cockpit,
 * la app) dando por hecho que cada fila es una asignación vigente. Si la fila reemplazada se quedara ahí
 * con una marca, cada lector tendría que acordarse de filtrarla. Moviéndola, la tabla sigue siendo «solo
 * lo vigente» para todos y el historial queda aparte.
 */
final readonly class CrewHistoryWriter
{
    /** Origen del rastro cuando lo deja una operación normal (API / admin). */
    public const string SOURCE_API = 'api';

    /** Origen del rastro cuando lo deja el saneo de datos viejos. */
    public const string SOURCE_BACKFILL = 'backfill';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * Devuelve false si la tripulación ya no estaba vigente (no hace nada: es idempotente).
     *
     * @param string|null $retiredAt momento del cambio (hora de pared de la app); por defecto, ahora
     */
    public function retire(
        string $crewId,
        string $reason,
        ?string $successorId = null,
        ?string $newVehicleId = null,
        string $source = self::SOURCE_API,
        ?string $retiredAt = null,
    ): bool {
        return (bool) $this->db->transaction(function () use ($crewId, $reason, $successorId, $newVehicleId, $source, $retiredAt): bool {
            $row = $this->db->table('fleet_crews')->where('id', $crewId)->lockForUpdate()->first();
            if (!$row instanceof stdClass) {
                return false;
            }

            $this->db->table('fleet_crew_history')->insertOrIgnore([
                'id' => (string) $row->id,
                'driver_id' => (string) $row->driver_id,
                'helper_ids' => is_string($row->helper_ids ?? null) ? $row->helper_ids : '[]',
                'vehicle_id' => (string) $row->vehicle_id,
                'assembled_for' => substr((string) $row->assembled_for, 0, 10),
                'shift_starts_at' => $row->shift_starts_at ?? null,
                'shift_ends_at' => $row->shift_ends_at ?? null,
                'crew_created_at' => $row->created_at ?? null,
                'reason' => $reason,
                'replaced_by_crew_id' => $successorId,
                'new_vehicle_id' => $newVehicleId,
                'source' => $source,
                'retired_at' => $retiredAt ?? Carbon::now()->format('Y-m-d H:i:s'),
            ]);

            return $this->db->table('fleet_crews')->where('id', $crewId)->delete() > 0;
        });
    }
}
