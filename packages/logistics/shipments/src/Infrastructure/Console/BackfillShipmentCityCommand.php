<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Infrastructure\ReadModel\ShipmentCityResolver;
use stdClass;

/**
 * Completa `shipments.city` en los envíos que todavía no la tienen (los anteriores a la columna).
 *
 * Idempotente: solo toca filas con la ciudad vacía y vuelve a dejar vacías las que no se pueden resolver, así
 * correrlo varias veces no cambia nada. Escribe con el query builder (no dispara eventos ni reemite hechos
 * del envío) y por lotes. Simulación por defecto; `--force` aplica. Lo corre solo el arranque y el deploy.
 */
final class BackfillShipmentCityCommand extends Command
{
    protected $signature = 'logistics:shipments:backfill-city
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=1000 : Tamaño de lote}';

    protected $description = 'Completa la ciudad de los envíos que no la tienen (desde la dirección o el nodo destino)';

    public function handle(ConnectionResolverInterface $connections, ShipmentCityResolver $cities): int
    {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        $db = $connections->connection();

        $resolved = 0;
        $unresolved = 0;

        $db->table('shipments')
            ->whereNull('city')
            ->orderBy('id')
            ->select(['id', 'address_text', 'destination_node_id'])
            ->chunkById($chunk, function (iterable $rows) use ($db, $cities, $force, &$resolved, &$unresolved): void {
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        continue;
                    }

                    $city = $cities->resolve(
                        is_string($row->address_text ?? null) ? $row->address_text : null,
                        is_string($row->destination_node_id ?? null) ? $row->destination_node_id : null,
                    );

                    if ($city === null) {
                        $unresolved++;

                        continue;
                    }

                    $resolved++;
                    if ($force) {
                        $db->table('shipments')->where('id', $row->id)->whereNull('city')->update(['city' => $city]);
                    }
                }
            });

        $this->info(($force ? 'Listo. ' : 'Simulación. ')."Con ciudad resuelta: {$resolved}. Sin ciudad reconocible: {$unresolved}.".($force ? '' : ' Usá --force para aplicar.'));

        return self::SUCCESS;
    }
}
