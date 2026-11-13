<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Command\OpenDelivery\OpenDelivery;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Backfill de CONSISTENCIA: abre la ENTREGA (OpenDelivery) de los envíos que YA están en la ruta de un
 * camión (parada asignada) pero NO tienen entrega en execution. Reconcilia el estado "Cargada/asignada a
 * un camión" (nivel envío/etiqueta) con la ENTREGA abierta (objeto de dominio de execution) que el POD
 * exige: sin esto el chofer ve "no hay entrega abierta para el envío" aunque la etiqueta figure Cargada.
 *
 * Es la contraparte de `advance-stuck-deliveries` (que avanza entregas YA EXISTENTES en PENDING): este
 * comando CREA la entrega que falta. NO re-emite etiquetas (solo toca execution).
 *
 * Idempotente: despacha OpenDelivery, que abre si falta y mueve al camión si ya existe en curso (respeta
 * DELIVERED/FAILED). Dry-run por defecto; `--force` ejecuta. `--shipment=` acota a envíos puntuales.
 */
final class OpenMissingDeliveriesCommand extends Command
{
    protected $signature = 'logistics:execution:open-missing-deliveries
        {--force : Ejecuta de verdad (sin esto es dry-run)}
        {--shipment=* : Solo estos shipment_id (vacío = todos los envíos en ruta sin entrega)}';

    protected $description = 'Abre la entrega de los envíos en ruta (asignados a un camión) que no tienen entrega en execution, para que el POD entre. Idempotente; no re-emite etiquetas.';

    public function handle(ConnectionInterface $connection, CommandBus $bus): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', (array) $this->option('shipment')),
            static fn (string $v): bool => $v !== '',
        ));

        $query = $connection->table('execution_driver_route_stops as s')
            ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
            ->leftJoin('execution_deliveries as d', 'd.shipment_id', '=', 's.shipment_id')
            ->whereNull('d.id');

        if ($only !== []) {
            $query->whereIn('s.shipment_id', $only);
        }

        $rows = $query->distinct()->get(['s.shipment_id', 'r.vehicle_id']);

        if ($rows->isEmpty()) {
            $this->info('No hay envíos en ruta sin entrega: nada que abrir.');

            return self::SUCCESS;
        }

        if ($this->option('force') === false || $this->option('force') === null) {
            $this->info('Dry-run: abriría '.$rows->count().' entrega(s). Pasá --force para ejecutar.');
            foreach ($rows as $row) {
                $this->line('  - envío '.(string) $row->shipment_id.' → camión '.(string) $row->vehicle_id);
            }

            return self::SUCCESS;
        }

        $opened = 0;
        foreach ($rows as $row) {
            $shipmentId = (string) $row->shipment_id;
            $vehicleId = (string) $row->vehicle_id;
            if (trim($shipmentId) === '' || trim($vehicleId) === '') {
                continue;
            }

            $bus->dispatch(new OpenDelivery($shipmentId, $vehicleId));
            $opened++;
        }

        $this->info("Abiertas {$opened} entrega(s) faltante(s). El POD de esos envíos ya entra.");

        return self::SUCCESS;
    }
}
