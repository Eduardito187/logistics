<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Resuelve, para la señal `loading.updated`, los canales del tema `loading` que cubren a un camión. Solo
 * lectura, por el query builder y con consultas baratas por índice; corre en la cola, nunca en un request.
 *
 * Dimensiones del canal (todas opcionales y tolerantes; si una no resuelve, se omite):
 *   - `city`   : departamento del nodo BASE del vehículo (`fleet_vehicles.home_base_node_id` →
 *                `network_nodes.department`). Es el andén/ciudad desde donde se carga el camión.
 *   - `driver` : chofer de la tripulación VIGENTE del vehículo hoy (`fleet_crews`: `vehicle_id` +
 *                `assembled_for` = hoy → `driver_id`). La carga ocurre en el presente, así que vale la del día.
 *
 * Las tablas de flota y de red se leen por valor (sin acoplar contextos). Nunca lanza: cualquier dimensión
 * que no se resuelva se omite, y `loading.all` siempre queda. Valida la forma UUID antes de comparar contra
 * columnas `uuid` (PostgreSQL rechaza otro texto con 22P02).
 */
final class LoadingSignalReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Canales (sin el prefijo `private-`) por los que sale `loading.updated` para el camión: `loading.all` y,
     * cuando resuelven, `loading.city.<depto>` y `loading.driver.<uuid>`.
     *
     * @return list<string>
     */
    public function channelsForVehicle(string $vehicleId): array
    {
        $vehicle = self::uuid($vehicleId);
        if ($vehicle === null) {
            return RealtimeChannels::forEntity(RealtimeChannels::TOPIC_LOADING, []);
        }

        return RealtimeChannels::forEntity(RealtimeChannels::TOPIC_LOADING, [
            'city' => $this->cityOf($vehicle),
            'driver' => $this->driverOf($vehicle),
        ]);
    }

    /**
     * Departamento del nodo base del vehículo (código de ciudad apto para canal), o null si no resuelve.
     */
    private function cityOf(string $vehicleId): ?string
    {
        $nodeId = self::uuid(DB::table('fleet_vehicles')->where('id', $vehicleId)->value('home_base_node_id'));
        if ($nodeId === null) {
            return null;
        }

        $department = DB::table('network_nodes')->where('id', $nodeId)->value('department');

        return is_string($department) && $department !== '' ? $department : null;
    }

    /**
     * Chofer de la tripulación vigente del vehículo hoy (la más reciente si hubiera varias), o null.
     */
    private function driverOf(string $vehicleId): ?string
    {
        $driverId = DB::table('fleet_crews')
            ->where('vehicle_id', $vehicleId)
            ->whereDate('assembled_for', Carbon::now()->toDateString())
            ->orderByDesc('updated_at')
            ->value('driver_id');

        return self::uuid($driverId);
    }

    /**
     * Solo UUIDs con forma válida, en minúscula; null en cualquier otro caso.
     */
    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
