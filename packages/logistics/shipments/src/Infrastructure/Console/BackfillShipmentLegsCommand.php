<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\Model\ShipmentLeg;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLegMapper;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLegRecord;
use stdClass;
use Throwable;

/**
 * Da a cada envío que todavía NO tiene tramos un ÚNICO tramo inicial (seq=1) que va de su origen a su
 * destino final, movido por flota propia. Es el cimiento del modelo hub-and-spoke para los envíos
 * anteriores a la tabla `shipment_legs`: las olas de ruteo partirán ese tramo en varios cuando el camino
 * lo pida.
 *
 * Idempotente: solo toca envíos SIN ningún tramo (clave estable `shipment_id`), así correrlo más de una
 * vez no duplica. Construye el tramo por la vía de dominio (respeta invariantes) pero PERSISTE directo por
 * el mapper + Eloquent, SIN reemitir eventos al outbox: es un backfill del lado de lectura, no re-ejecuta
 * el flujo (ver regla del proyecto). Por lotes (`chunkById`) para escalar a millones. Simulación por
 * defecto; `--force` aplica.
 */
final class BackfillShipmentLegsCommand extends Command
{
    protected $signature = 'logistics:shipments:backfill-legs
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=1000 : Tamaño de lote}';

    protected $description = 'Crea el tramo inicial (origen → destino) de los envíos que todavía no tienen tramos';

    public function handle(ConnectionResolverInterface $connections, ShipmentLegMapper $mapper): int
    {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        $db = $connections->connection();

        $created = 0;
        $skipped = 0;

        $db->table('shipments')
            // Solo los envíos que no tienen NINGÚN tramo todavía (idempotencia por `shipment_id`).
            ->whereNotExists(static function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('shipment_legs')
                    ->whereColumn('shipment_legs.shipment_id', 'shipments.id');
            })
            ->orderBy('id')
            ->select(['id', 'status', 'origin_node_id', 'origin_department', 'destination_node_id', 'destination_department'])
            ->chunkById($chunk, function (iterable $rows) use ($mapper, $force, &$created, &$skipped): void {
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        $skipped++;

                        continue;
                    }

                    $id = self::text($row->id ?? null);
                    if ($id === null) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $itinerary = $this->itineraryFor($id, $row);
                    } catch (Throwable $e) {
                        $skipped++;
                        $this->warn("Envío {$id}: no se pudo armar el tramo ({$e->getMessage()}).");

                        continue;
                    }

                    $created++;
                    if ($force) {
                        $this->persist($mapper, $itinerary);
                    }
                }
            });

        $this->info(($force ? 'Listo. ' : 'Simulación. ')."Tramos iniciales creados: {$created}. Omitidos: {$skipped}.".($force ? '' : ' Usá --force para aplicar.'));

        return self::SUCCESS;
    }

    /**
     * Arma el itinerario de un tramo (seq=1) del envío: de su origen a su destino final, flota propia y el
     * estado derivado del estado del envío.
     */
    private function itineraryFor(string $id, stdClass $row): ShipmentItinerary
    {
        $shipmentId = ShipmentId::fromString($id);

        $leg = ShipmentLeg::create(
            ShipmentLegId::generate(),
            1,
            self::text($row->origin_node_id ?? null),
            self::text($row->destination_node_id ?? null),
            self::text($row->origin_department ?? null),
            self::text($row->destination_department ?? null),
            HaulageMode::OwnFleet,
            null,
            self::legStatusFor(self::text($row->status ?? null)),
        );

        return ShipmentItinerary::fromLegs($shipmentId, [$leg]);
    }

    /**
     * Persiste el tramo directo por el mapper + Eloquent, sin pasar por el agregado emisor de eventos.
     * `updateOrCreate` por id deja la operación re-segura dentro de un mismo lote.
     */
    private function persist(ShipmentLegMapper $mapper, ShipmentItinerary $itinerary): void
    {
        foreach ($itinerary->legs() as $leg) {
            $row = $mapper->toLegRow($itinerary, $leg);
            ShipmentLegRecord::query()->updateOrCreate(['id' => $row['id']], $row);
        }
    }

    /**
     * Estado del tramo inicial a partir del estado del envío: entregado → cerrado, en ejecución → en
     * tránsito, cualquier otro → pendiente. Tolerante a un estado desconocido (cae a pendiente).
     */
    private static function legStatusFor(?string $shipmentStatus): LegStatus
    {
        $status = $shipmentStatus !== null ? ShipmentStatus::tryFromLoose($shipmentStatus) : null;

        return match ($status) {
            ShipmentStatus::Delivered => LegStatus::Closed,
            ShipmentStatus::InExecution => LegStatus::InTransit,
            default => LegStatus::Pending,
        };
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
