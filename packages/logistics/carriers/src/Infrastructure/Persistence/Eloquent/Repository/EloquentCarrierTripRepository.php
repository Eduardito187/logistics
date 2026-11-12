<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper\CarrierTripMapper;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;

/**
 * Implementación Eloquent del repositorio de viajes de transportista.
 *
 * `save` es un upsert de la cabecera + sincronización de la carga (agrega los envíos nuevos con su
 * posición y quita los removidos), dentro de la transacción del comando (bus transaccional).
 */
final readonly class EloquentCarrierTripRepository implements CarrierTripRepository
{
    private const SHIPMENTS_TABLE = 'carrier_trip_shipments';

    /** Tamaño de lote para whereIn (lejos del límite de parámetros de PostgreSQL). */
    private const CHUNK = 1000;

    public function __construct(
        private CarrierTripMapper $mapper = new CarrierTripMapper,
    ) {}

    public function nextIdentity(): CarrierTripId
    {
        return CarrierTripId::generate();
    }

    public function save(CarrierTrip $trip): void
    {
        $row = $this->mapper->toRow($trip);
        CarrierTripRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        $tripId = $row['id'];
        $wanted = $trip->shipmentIds();
        $current = $this->shipmentIdsOf($tripId);

        $removed = array_values(array_diff($current, $wanted));
        foreach (array_chunk($removed, self::CHUNK) as $chunk) {
            DB::table(self::SHIPMENTS_TABLE)->where('trip_id', $tripId)->whereIn('shipment_id', $chunk)->delete();
        }

        $added = array_values(array_diff($wanted, $current));
        if ($added === []) {
            return;
        }

        $nextPosition = (int) DB::table(self::SHIPMENTS_TABLE)->where('trip_id', $tripId)->max('position') + 1;
        $now = new DateTimeImmutable;
        $rows = [];
        foreach ($added as $shipmentId) {
            $rows[] = [
                'trip_id' => $tripId,
                'shipment_id' => $shipmentId,
                'position' => $nextPosition++,
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table(self::SHIPMENTS_TABLE)->insertOrIgnore($chunk);
        }
    }

    public function findById(CarrierTripId $id): ?CarrierTrip
    {
        $record = CarrierTripRecord::query()->find($id->toString());

        return $record instanceof CarrierTripRecord
            ? $this->mapper->toDomain($record, $this->shipmentIdsOf($record->id))
            : null;
    }

    public function findByIdForUpdate(CarrierTripId $id): ?CarrierTrip
    {
        $record = CarrierTripRecord::query()->whereKey($id->toString())->lockForUpdate()->first();

        return $record instanceof CarrierTripRecord
            ? $this->mapper->toDomain($record, $this->shipmentIdsOf($record->id))
            : null;
    }

    public function shipmentsHeldByOtherActiveTrips(array $shipmentIds, ?CarrierTripId $except = null): array
    {
        /** @var array<string, string> $held */
        $held = [];

        foreach (array_chunk(array_values(array_unique($shipmentIds)), self::CHUNK) as $chunk) {
            $query = DB::table(self::SHIPMENTS_TABLE.' as s')
                ->join('carrier_trips as t', 't.id', '=', 's.trip_id')
                ->whereIn('s.shipment_id', $chunk)
                ->whereIn('t.status', CarrierTripStatus::activeValues());

            if ($except !== null) {
                $query->where('t.id', '!=', $except->toString());
            }

            foreach ($query->get(['s.shipment_id', 's.trip_id']) as $row) {
                $held[(string) $row->shipment_id] = (string) $row->trip_id;
            }
        }

        return $held;
    }

    /**
     * Ids de la carga de un viaje en orden de carga.
     *
     * @return list<string>
     */
    private function shipmentIdsOf(string $tripId): array
    {
        // array_values() deja la forma list<string> que exige el @return (all() tipa array<int, string>).
        return array_values(
            DB::table(self::SHIPMENTS_TABLE)
                ->where('trip_id', $tripId)
                ->orderBy('position')
                ->pluck('shipment_id')
                ->map(static fn (mixed $id): string => \is_string($id) ? $id : '')
                ->all(),
        );
    }
}
