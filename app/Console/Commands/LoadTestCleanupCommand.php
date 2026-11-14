<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Borra SOLO lo que sembró `loadtest:prepare` (y lo que las corridas de k6 generaron colgando de esas
 * filas: escaneos, POD, pings, sesiones de login), identificado por los prefijos del pool de carga.
 *
 * Nunca usa truncate ni toca filas ajenas: cada borrado es un `DELETE ... WHERE columna IN (ids del pool)`,
 * y los ids del pool salen de las claves con prefijo (placa LOADTEST-, licencia LT-DRV-, email
 * lt-…@loadtest.dismac.test, external_id LT-lote-chofer-parada + cliente "LT …", huella lt-device-, canal
 * LT-loadtest). Se borra de hijos a padres y en tandas, sin una transacción gigante: si se interrumpe,
 * volver a correrlo termina el trabajo (idempotente).
 *
 * Dry-run por defecto (muestra cuántas filas borraría por tabla); `--force` para borrar. Vetado en
 * producción. Las tablas o columnas que no existan en el entorno se saltean.
 *
 * No limpia: los mensajes del outbox ya emitidos (historial de eventos, se purgan por su retención) ni
 * las claves efímeras de Valkey (idempotencia, snapshots de dispositivo), que vencen solas por TTL.
 */
final class LoadTestCleanupCommand extends Command
{
    /** Tamaño de tanda de los `whereIn` (acota la lista de parámetros por sentencia). */
    private const int CHUNK = 1000;

    /**
     * Plan de borrado, de hijos a padres: [tabla, columna, grupo de ids del pool].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array PLAN = [
        // Ejecución en terreno.
        ['execution_scan_events', 'delivery_id', 'deliveries'],
        ['execution_scan_batch_items', 'shipment_id', 'shipments'],
        ['execution_delivery_evidence', 'shipment_id', 'shipments'],
        ['execution_delivery_issues', 'shipment_id', 'shipments'],
        ['execution_node_receptions', 'shipment_id', 'shipments'],
        ['execution_deliveries', 'shipment_id', 'shipments'],
        ['execution_driver_route_stops', 'shipment_id', 'shipments'],
        ['execution_driver_route_stops', 'route_id', 'routes'],
        ['execution_vehicle_incidents', 'vehicle_id', 'vehicles'],
        ['execution_driver_routes', 'vehicle_id', 'vehicles'],
        // Telemetría.
        ['telemetry_stop_etas', 'shipment_id', 'shipments'],
        ['telemetry_stop_etas', 'vehicle_id', 'vehicles'],
        ['telemetry_alerts', 'vehicle_id', 'vehicles'],
        ['telemetry_trips', 'vehicle_id', 'vehicles'],
        ['telemetry_vehicle_positions', 'vehicle_id', 'vehicles'],
        ['telemetry_pings', 'vehicle_id', 'vehicles'],
        // Seguimiento, avisos y proyecciones por envío.
        ['tracking_units', 'shipment_id', 'shipments'],
        ['tracking_satisfaction_surveys', 'shipment_id', 'shipments'],
        ['tracking_push_subscriptions', 'shipment_id', 'shipments'],
        ['tracking_shipments', 'shipment_id', 'shipments'],
        ['tracking_shipments', 'order_reference', 'references'],
        ['notifications', 'shipment_id', 'shipments'],
        ['reporting_shipments', 'shipment_id', 'shipments'],
        ['compensation_cases', 'shipment_id', 'shipments'],
        ['carrier_trip_shipments', 'shipment_id', 'shipments'],
        // Planificación.
        ['planning_plan_stops', 'shipment_id', 'shipments'],
        ['planning_shipment_replans', 'shipment_id', 'shipments'],
        ['planning_shipment_requirements', 'shipment_id', 'shipments'],
        ['planning_plans', 'vehicle_id', 'vehicles'],
        // Envíos.
        ['shipment_units', 'shipment_id', 'shipments'],
        ['shipment_lines', 'shipment_id', 'shipments'],
        ['shipments', 'id', 'shipments'],
        // Flota.
        ['fleet_crews', 'vehicle_id', 'vehicles'],
        ['fleet_pre_trip_checklists', 'vehicle_id', 'vehicles'],
        ['fleet_vehicle_fitness_log', 'vehicle_id', 'vehicles'],
        ['fleet_drivers', 'id', 'drivers'],
        ['fleet_vehicles', 'id', 'vehicles'],
        // Identidad: tokens y dispositivos antes que las cuentas.
        ['personal_access_tokens', 'device_id', 'devices'],
        ['user_roles', 'user_id', 'users'],
        ['user_preferences', 'user_id', 'users'],
        ['app_outbox_failures', 'user_id', 'users'],
        ['notification_reads', 'user_id', 'users'],
        ['notification_read_marks', 'user_id', 'users'],
        ['devices', 'id', 'devices'],
        ['users', 'id', 'users'],
        // Canal del rastreo público.
        ['api_channels', 'id', 'channels'],
    ];

    protected $signature = 'loadtest:cleanup
        {--pool='.LoadTestPrepareCommand::DEFAULT_OUTPUT.' : Pool JSON to remove once the data is deleted}
        {--force : Actually delete (default is a dry run)}';

    protected $description = 'Delete only the load-test pool seeded by loadtest:prepare (LT prefix)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('loadtest:cleanup refuses to run in production.');

            return self::FAILURE;
        }

        $force = $this->option('force') === true;
        $ids = $this->poolIds();

        $rows = [];
        $total = 0;
        foreach (self::PLAN as [$table, $column, $group]) {
            $count = $this->purge($table, $column, $ids[$group] ?? [], $force);
            if ($count > 0) {
                $rows[] = [$table, $column, $count];
                $total += $count;
            }
        }

        if ($rows === []) {
            $this->info('No load-test pool data found; nothing to clean.');
        } else {
            $this->table(['Table', 'Matched by', $force ? 'Deleted rows' : 'Rows to delete'], $rows);
        }

        if (!$force) {
            $this->warn(sprintf('Dry run: %d rows would be deleted. Re-run with --force to delete them.', $total));

            return self::SUCCESS;
        }

        $this->removePoolFile();
        $this->info(sprintf('Load-test pool cleaned: %d rows deleted.', $total));

        return self::SUCCESS;
    }

    /**
     * Ids del pool por grupo, resueltos desde las claves con prefijo. Es la ÚNICA fuente de qué se borra.
     *
     * @return array<string, list<string>>
     */
    private function poolIds(): array
    {
        $shipments = $this->ids(
            'shipments',
            'id',
            fn (): array => DB::table('shipments')
                ->where('external_id', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)
                ->where('customer_name', 'like', LoadTestPrepareCommand::CUSTOMER_PREFIX.'%')
                ->pluck('id')
                ->all(),
        );
        $vehicles = $this->ids(
            'fleet_vehicles',
            'id',
            fn (): array => DB::table('fleet_vehicles')
                ->where('plate', 'like', LoadTestPrepareCommand::PLATE_PREFIX.'%')
                ->pluck('id')
                ->all(),
        );
        $users = $this->ids(
            'users',
            'id',
            fn (): array => DB::table('users')
                ->where('email', 'like', LoadTestPrepareCommand::EMAIL_PREFIX.'%@'.LoadTestPrepareCommand::EMAIL_DOMAIN)
                ->pluck('id')
                ->all(),
        );

        return [
            'shipments' => $shipments,
            'vehicles' => $vehicles,
            'users' => $users,
            'drivers' => $this->ids(
                'fleet_drivers',
                'id',
                fn (): array => DB::table('fleet_drivers')
                    ->where('license_number', 'like', LoadTestPrepareCommand::LICENSE_PREFIX.'%')
                    ->pluck('id')
                    ->all(),
            ),
            'references' => $this->ids(
                'shipments',
                'external_id',
                fn (): array => DB::table('shipments')
                    ->where('external_id', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)
                    ->where('customer_name', 'like', LoadTestPrepareCommand::CUSTOMER_PREFIX.'%')
                    ->pluck('external_id')
                    ->all(),
            ),
            'deliveries' => $this->related('execution_deliveries', 'shipment_id', $shipments),
            'routes' => $this->related('execution_driver_routes', 'vehicle_id', $vehicles),
            'devices' => array_values(array_unique([
                ...$this->ids(
                    'devices',
                    'fingerprint',
                    fn (): array => DB::table('devices')
                        ->where('fingerprint', 'like', LoadTestPrepareCommand::DEVICE_PREFIX.'%')
                        ->pluck('id')
                        ->all(),
                ),
                ...$this->related('devices', 'user_id', $users),
            ])),
            'channels' => $this->ids(
                'api_channels',
                'name',
                fn (): array => DB::table('api_channels')
                    ->where('name', LoadTestPrepareCommand::CHANNEL_NAME)
                    ->pluck('id')
                    ->all(),
            ),
        ];
    }

    /**
     * Ejecuta la consulta de ids solo si la tabla y la columna de filtro existen en este entorno.
     *
     * @param  callable(): array<array-key, mixed> $query
     * @return list<string>
     */
    private function ids(string $table, string $column, callable $query): array
    {
        if (!$this->exists($table, $column)) {
            return [];
        }

        return $this->stringList($query());
    }

    /**
     * Ids de las filas de `$table` que cuelgan de los ids padre dados (p. ej. entregas de los envíos).
     *
     * @param  list<string> $parentIds
     * @return list<string>
     */
    private function related(string $table, string $column, array $parentIds): array
    {
        if ($parentIds === [] || !$this->exists($table, $column)) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
            array_push($ids, ...$this->stringList(DB::table($table)->whereIn($column, $chunk)->pluck('id')->all()));
        }

        return $ids;
    }

    /**
     * Borra (o, en dry-run, cuenta) las filas de `$table` cuya `$column` está en los ids del pool.
     *
     * @param list<string> $ids
     */
    private function purge(string $table, string $column, array $ids, bool $force): int
    {
        if ($ids === [] || !$this->exists($table, $column)) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);
            $total += $force ? $query->delete() : $query->count();
        }

        return $total;
    }

    private function exists(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * El pool exportado deja de servir al borrar los datos (tokens y secreto del canal ya no existen).
     */
    private function removePoolFile(): void
    {
        $option = $this->option('pool');
        $pool = is_string($option) && trim($option) !== '' ? trim($option) : LoadTestPrepareCommand::DEFAULT_OUTPUT;
        $isAbsolute = str_starts_with($pool, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $pool) === 1;
        $path = $isAbsolute ? $pool : base_path($pool);

        if (File::isFile($path)) {
            File::delete($path);
            $this->line("Removed {$path}");
        }
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $list[] = (string) $value;
            }
        }

        return $list;
    }
}
