<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Staff\StaffCity;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;

/**
 * Completa `users.city` (ciudad = departamento de la persona) SOLO donde está vacía.
 *
 * De dónde sale la ciudad, en este orden:
 *   1. del departamento de su tienda / nodo (`users.node_id`);
 *   2. si es chofer (`users.driver_id`), de la base del vehículo de su tripulación más reciente;
 *   3. si es chofer y nunca tuvo tripulación, de la base de los vehículos que tiene habilitados, siempre
 *      que estén en un único departamento (si están repartidos en varios, no se adivina).
 * Si no hay de dónde sacarla, queda vacía y se asigna a mano desde la ficha del personal.
 *
 * Idempotente: no pisa una ciudad ya cargada, así que correrlo varias veces no cambia nada. Escribe con el
 * query builder y por lotes. Simulación por defecto; `--force` aplica. Lo corre el arranque y el deploy.
 */
final class BackfillStaffCityCommand extends Command
{
    protected $signature = 'logistics:staff:backfill-city
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=500 : Tamaño de lote}';

    protected $description = 'Completa la ciudad (departamento) del personal que no la tiene, desde su nodo o su vehículo';

    public function handle(ConnectionResolverInterface $connections): int
    {
        if (!Schema::hasColumn('users', 'city')) {
            $this->warn('La columna users.city todavía no existe (falta migrar). No se hizo nada.');

            return self::SUCCESS;
        }

        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        $db = $connections->connection();

        $nodeCities = $this->nodeCities($db);
        $vehicleCities = $this->vehicleCities($db, $nodeCities);

        $fromNode = 0;
        $fromCrew = 0;
        $fromEnabled = 0;
        $unresolved = 0;

        $db->table('users')
            ->whereNull('city')
            ->orderBy('id')
            ->select(['id', 'node_id', 'driver_id'])
            ->chunkById($chunk, function (iterable $rows) use ($db, $nodeCities, $vehicleCities, $force, &$fromNode, &$fromCrew, &$fromEnabled, &$unresolved): void {
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        continue;
                    }

                    $nodeId = is_string($row->node_id ?? null) ? $row->node_id : '';
                    $driverId = is_string($row->driver_id ?? null) ? $row->driver_id : '';

                    $city = $nodeCities[$nodeId] ?? null;
                    if ($city !== null) {
                        $fromNode++;
                    } elseif ($driverId !== '' && ($city = $this->cityFromLatestCrew($db, $driverId, $vehicleCities)) !== null) {
                        $fromCrew++;
                    } elseif ($driverId !== '' && ($city = $this->cityFromEnabledVehicles($db, $driverId, $vehicleCities)) !== null) {
                        $fromEnabled++;
                    }

                    if ($city === null) {
                        $unresolved++;

                        continue;
                    }

                    if ($force) {
                        $db->table('users')->where('id', $row->id)->whereNull('city')->update(['city' => $city]);
                    }
                }
            });

        $this->info(sprintf(
            '%sCiudad resuelta por nodo: %d, por tripulación: %d, por vehículos habilitados: %d. Sin dato de dónde sacarla: %d.%s',
            $force ? 'Listo. ' : 'Simulación. ',
            $fromNode,
            $fromCrew,
            $fromEnabled,
            $unresolved,
            $force ? '' : ' Usá --force para aplicar.',
        ));

        return self::SUCCESS;
    }

    /**
     * Ciudad (código de departamento) de cada nodo de la red que la tiene cargada.
     *
     * @return array<string, string>
     */
    private function nodeCities(ConnectionInterface $db): array
    {
        $cities = [];
        foreach ($db->table('network_nodes')->whereNotNull('department')->get(['id', 'department']) as $node) {
            $city = StaffCity::code(is_string($node->department ?? null) ? $node->department : null);
            if ($city !== null) {
                $cities[(string) $node->id] = $city;
            }
        }

        return $cities;
    }

    /**
     * Ciudad de cada vehículo: la del nodo que es su base. Vacío si la flota no está disponible.
     *
     * @param  array<string, string> $nodeCities
     * @return array<string, string>
     */
    private function vehicleCities(ConnectionInterface $db, array $nodeCities): array
    {
        $cities = [];
        try {
            foreach ($db->table('fleet_vehicles')->get(['id', 'home_base_node_id']) as $vehicle) {
                $city = $nodeCities[(string) ($vehicle->home_base_node_id ?? '')] ?? null;
                if ($city !== null) {
                    $cities[(string) $vehicle->id] = $city;
                }
            }
        } catch (Throwable) {
            // tabla de flota ausente en este entorno: los choferes quedan sin resolver por esta vía.
        }

        return $cities;
    }

    /**
     * @param array<string, string> $vehicleCities
     */
    private function cityFromLatestCrew(ConnectionInterface $db, string $driverId, array $vehicleCities): ?string
    {
        try {
            $vehicleId = $db->table('fleet_crews')
                ->where('driver_id', $driverId)
                ->orderByDesc('assembled_for')
                ->orderByDesc('created_at')
                ->value('vehicle_id');
        } catch (Throwable) {
            return null;
        }

        return is_string($vehicleId) ? ($vehicleCities[$vehicleId] ?? null) : null;
    }

    /**
     * Ciudad común a los vehículos habilitados del chofer; null si no tiene o si están en más de una.
     *
     * @param array<string, string> $vehicleCities
     */
    private function cityFromEnabledVehicles(ConnectionInterface $db, string $driverId, array $vehicleCities): ?string
    {
        try {
            $raw = $db->table('fleet_drivers')->where('id', $driverId)->value('enabled_vehicle_ids');
        } catch (Throwable) {
            return null;
        }

        $ids = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($ids)) {
            return null;
        }

        $found = [];
        foreach ($ids as $id) {
            if (is_string($id) && isset($vehicleCities[$id])) {
                $found[$vehicleCities[$id]] = true;
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }
}
