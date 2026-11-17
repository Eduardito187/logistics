<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\BuildShipmentItinerary\BuildShipmentItinerary;
use stdClass;
use Throwable;

/**
 * Reconstruye el itinerario de TODOS los envíos aplicando la decisión de acarreo + el router (propia vs.
 * tercerizado por departamento destino, tramos por el modelo hub-and-spoke).
 *
 * Útil para datos existentes: el backfill previo (`logistics:shipments:backfill-legs`) dejó a cada envío un
 * único tramo de flota propia; este comando lo reemplaza por el itinerario real según la configuración de
 * tercerización vigente.
 *
 * Idempotente: cada envío se reconstruye por el comando BuildShipmentItinerary, que REEMPLAZA sus tramos
 * (no duplica), dentro de la transacción del CommandBus. Por lotes (`chunkById`) para escalar a millones.
 * Simulación por defecto; `--force` aplica.
 */
final class RebuildShipmentItinerariesCommand extends Command
{
    protected $signature = 'logistics:shipments:rebuild-itineraries
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=1000 : Tamaño de lote}';

    protected $description = 'Reconstruye el itinerario (decisión + router) de todos los envíos';

    public function handle(ConnectionResolverInterface $connections, CommandBus $commandBus): int
    {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));

        $processed = 0;
        $failed = 0;

        $connections->connection()->table('shipments')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById($chunk, function (iterable $rows) use ($commandBus, $force, &$processed, &$failed): void {
                foreach ($rows as $row) {
                    $id = $row instanceof stdClass ? self::text($row->id ?? null) : null;
                    if ($id === null) {
                        continue;
                    }

                    if (!$force) {
                        $processed++;

                        continue;
                    }

                    try {
                        $commandBus->dispatch(new BuildShipmentItinerary($id));
                        $processed++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("Envío {$id}: no se pudo reconstruir el itinerario ({$e->getMessage()}).");
                    }
                }
            });

        $this->info(($force ? 'Listo. ' : 'Simulación. ')."Itinerarios reconstruidos: {$processed}. Fallidos: {$failed}.".($force ? '' : ' Usá --force para aplicar.'));

        return self::SUCCESS;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
