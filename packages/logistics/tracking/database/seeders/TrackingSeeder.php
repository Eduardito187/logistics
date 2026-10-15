<?php

declare(strict_types=1);

namespace Logistics\Tracking\Database\Seeders;

use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Database\Factories\TrackingRecordFactory;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\UnitTrackingRecord;

/**
 * Seeder propio del contexto de seguimiento con datos de demo realistas.
 *
 * Lo invoca el integrador desde el DatabaseSeeder raíz (no se registra solo). El read model tiene un
 * seguimiento por envío (tracking_shipments.shipment_id es único), así que lee los envíos REALES ya
 * sembrados y crea un seguimiento por cada uno, repartiendo un abanico de estados representativos
 * (registrado, preparado, en camino con GPS, llegando, entregado, fallido). El primero queda como el
 * enlace público estable de la demo (token conocido). Es idempotente (updateOrCreate por envío con id
 * y token deterministas). Si todavía no hay envíos sembrados —p. ej. al correr el seeder aislado en un
 * test— cae en el abanico generado por factory con uuids aleatorios.
 */
final class TrackingSeeder extends Seeder
{
    /** Ruta demo compartida: las paradas en camino cuelgan de ella para ilustrar el avance en cascada. */
    private const DEMO_ROUTE_ID = '0192f0a0-0000-7000-8000-0000000000f1';

    /**
     * Abanico de estados visibles a repartir cíclicamente entre los envíos reales.
     *
     * @var list<VisibleStatus>
     */
    private const STATUS_CYCLE = [
        VisibleStatus::OnTheWay,
        VisibleStatus::Registered,
        VisibleStatus::Prepared,
        VisibleStatus::Arriving,
        VisibleStatus::Delivered,
        VisibleStatus::Failed,
    ];

    public function run(): void
    {
        $this->seedShipmentTrackings();
        $this->seedUnitTrackings();
    }

    /**
     * Siembra el read model por ENVÍO. Idempotente: si ya hay seguimientos de demo sembrados, no se
     * vuelve a sembrar. El token es determinista por índice mientras que el upsert es por shipment_id;
     * si el conjunto de envíos cambia entre corridas, un índice reusado chocaría con un token ya tomado
     * por otro envío (tracking_shipments_token_unique). El contexto Tracking es dueño exclusivo de su
     * tabla, así que basta con que tenga filas.
     */
    private function seedShipmentTrackings(): void
    {
        if (TrackingRecord::query()->exists()) {
            return;
        }

        /** @var list<string> $shipmentIds */
        $shipmentIds = ShipmentRecord::query()->orderBy('external_id')->pluck('id')->all();

        if ($shipmentIds === []) {
            $this->seedFromFactories();

            return;
        }

        foreach ($shipmentIds as $index => $shipmentId) {
            $status = self::STATUS_CYCLE[$index % count(self::STATUS_CYCLE)];
            $this->upsertTracking($shipmentId, $status, $index);
        }
    }

    /**
     * Siembra el read model por BULTO a partir de los seguimientos ya sembrados y de los bultos REALES
     * de cada envío (shipment_units). El estado de cada bulto se alinea con el estado visible de su
     * envío. Idempotente y UUID-estable: la pk se deriva del unit_id y solo siembra si la tabla está
     * vacía, así re-sembrar no duplica ni regenera ids (ver [[no-destructive-db-ops]]).
     */
    private function seedUnitTrackings(): void
    {
        if (UnitTrackingRecord::query()->exists()) {
            return;
        }

        /** @var array<string, string> $statusByShipment */
        $statusByShipment = TrackingRecord::query()->pluck('status', 'shipment_id')->all();
        if ($statusByShipment === []) {
            return;
        }

        $now = (new DateTimeImmutable)->format(DateTimeImmutable::ATOM);

        /** @var iterable<PhysicalUnitRecord> $units */
        $units = PhysicalUnitRecord::query()
            ->whereIn('shipment_id', array_keys($statusByShipment))
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $shipmentStatus = $statusByShipment[$unit->shipment_id] ?? VisibleStatus::Registered->value;
            $unitStatus = $this->unitStatusFor($shipmentStatus);

            UnitTrackingRecord::query()->updateOrCreate(
                ['unit_id' => $unit->id],
                [
                    'id' => $this->deterministicUuid('unit-tracking-'.$unit->id),
                    'shipment_id' => $unit->shipment_id,
                    'status' => $unitStatus->value,
                    'last_location_id' => null,
                    'last_seen_at' => $unitStatus === UnitStatus::Registered ? null : $now,
                ],
            );
        }
    }

    /**
     * Mapea el estado visible del envío al estado del bulto para la demo (el envío entregado deja sus
     * bultos entregados; en camino, cargados; etc.).
     */
    private function unitStatusFor(string $shipmentStatus): UnitStatus
    {
        return match ($shipmentStatus) {
            VisibleStatus::Delivered->value => UnitStatus::Delivered,
            VisibleStatus::Failed->value => UnitStatus::Failed,
            VisibleStatus::OnTheWay->value, VisibleStatus::Arriving->value => UnitStatus::Loaded,
            VisibleStatus::Prepared->value => UnitStatus::Prepared,
            default => UnitStatus::Registered,
        };
    }

    /**
     * Crea/actualiza el seguimiento de un envío real con atributos coherentes con su estado visible.
     * id y token son deterministas (derivados del índice/envío) para que re-sembrar sea idempotente.
     */
    private function upsertTracking(string $shipmentId, VisibleStatus $status, int $index): void
    {
        $position = ($index % 6) + 1;

        // Base común.
        $attributes = [
            'id' => $this->deterministicUuid('tracking-'.$shipmentId),
            'token' => sprintf('demo-tracking-token-%016d', $index + 1),
            'status' => $status->value,
            'route_id' => self::DEMO_ROUTE_ID,
            'route_position' => $position,
            'remaining_stops' => 0,
            'eta' => null,
            'last_lat' => null,
            'last_lng' => null,
            'last_position_at' => null,
        ];

        // Los estados en movimiento traen posición GPS fresca, ETA y paradas por delante.
        if (in_array($status, [VisibleStatus::OnTheWay, VisibleStatus::Arriving], true)) {
            $minutesAhead = $status === VisibleStatus::Arriving ? 15 : 90;
            $attributes['remaining_stops'] = $status === VisibleStatus::Arriving ? 1 : max(0, $position - 1);
            $attributes['eta'] = (new DateTimeImmutable(sprintf('+%d minutes', $minutesAhead)))->format(DateTimeImmutable::ATOM);
            $attributes['last_lat'] = '-16.5000000';
            $attributes['last_lng'] = '-68.1500000';
            $attributes['last_position_at'] = (new DateTimeImmutable)->format(DateTimeImmutable::ATOM);
        }

        TrackingRecord::query()->updateOrCreate(['shipment_id' => $shipmentId], $attributes);
    }

    /**
     * Abanico de demo por factory (uuids aleatorios) para cuando no hay envíos reales sembrados.
     */
    private function seedFromFactories(): void
    {
        TrackingRecordFactory::new()->withStatus(VisibleStatus::Registered)->count(4)->create();
        TrackingRecordFactory::new()->withStatus(VisibleStatus::Prepared)->count(4)->create();
        TrackingRecordFactory::new()->onTheWay()->count(6)->create();
        TrackingRecordFactory::new()->withStatus(VisibleStatus::Arriving)->count(3)->create();
        TrackingRecordFactory::new()->delivered()->count(8)->create();
        TrackingRecordFactory::new()->withStatus(VisibleStatus::Failed)->count(2)->create();
    }

    /**
     * Deriva un uuid determinista (forma v4) desde una semilla estable, para PKs de demo idempotentes.
     */
    private function deterministicUuid(string $seed): string
    {
        $hash = md5($seed);

        return sprintf(
            '%08s-%04s-4%03s-%04x-%012s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
            substr($hash, 20, 12),
        );
    }
}
