<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\ReadSide;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Application\Contract\CarrierAssignmentReader;
use Logistics\Carriers\Application\ReadModel\CarrierAssignmentView;
use Throwable;

/**
 * Lector SQL de la carga asignada a transportistas (CQRS, lado de lectura).
 *
 * Arma la página en un número FIJO de consultas, sin importar cuántos viajes/envíos/productos haya
 * (sin N+1): viajes de la página (LIMIT/OFFSET) → carga de esos viajes → envíos → unidades+líneas →
 * nombres de nodos y transportistas. Cada lista de ids se consulta en lotes de whereIn.
 *
 * Lee tablas de otros contextos (network_nodes, shipments, shipment_lines, shipment_units) en SOLO
 * lectura y sin importar sus clases: el acoplamiento es al esquema de lectura, no al dominio ajeno.
 *
 * Regla de producto (dueño): nombre + SKU siempre; si falta el nombre se usa el SKU; si faltan ambos,
 * "Producto". Nunca null/vacío ni "bulto".
 *
 * @phpstan-import-type ShipmentShape from CarrierAssignmentView
 * @phpstan-import-type UnitShape from CarrierAssignmentView
 */
final readonly class DbCarrierAssignmentReader implements CarrierAssignmentReader
{
    private const CHUNK = 1000;

    private const PRODUCT_FALLBACK = 'Producto';

    public function assignments(?string $carrierId, array $statuses, int $limit, int $offset): array
    {
        $trips = $this->filtered($carrierId, $statuses)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->offset(max(0, $offset))
            ->limit(max(1, $limit))
            ->get(['id', 'carrier_id', 'corridor_from', 'corridor_to', 'reference', 'status', 'scheduled_at', 'started_at', 'closed_at']);

        if ($trips->isEmpty()) {
            return [];
        }

        $tripIds = array_values($trips->map(static fn (object $row): string => self::str($row->id))->all());

        // Carga de los viajes de la página: trip_id → [shipment_id...] en orden de carga.
        /** @var array<string, list<string>> $loadByTrip */
        $loadByTrip = [];
        /** @var list<string> $shipmentIds */
        $shipmentIds = [];
        foreach (array_chunk($tripIds, self::CHUNK) as $chunk) {
            $rows = DB::table('carrier_trip_shipments')
                ->whereIn('trip_id', $chunk)
                ->orderBy('trip_id')
                ->orderBy('position')
                ->get(['trip_id', 'shipment_id']);
            foreach ($rows as $row) {
                $shipmentId = self::str($row->shipment_id);
                $loadByTrip[self::str($row->trip_id)][] = $shipmentId;
                $shipmentIds[] = $shipmentId;
            }
        }
        $shipmentIds = array_values(array_unique($shipmentIds));

        $shipments = $this->shipments($shipmentIds);
        $units = $this->units($shipmentIds);

        // Nombres legibles de nodos (corredor + destino de cada envío) y de transportistas, en lote.
        $nodeRefs = [];
        foreach ($trips as $trip) {
            $nodeRefs[] = self::str($trip->corridor_from);
            $nodeRefs[] = self::str($trip->corridor_to);
        }
        foreach ($shipments as $shipment) {
            if ($shipment['destination_node_id'] !== null) {
                $nodeRefs[] = $shipment['destination_node_id'];
            }
        }
        $nodeNames = $this->nodeNames($nodeRefs);
        $carrierNames = $this->carrierNames(
            array_values($trips->map(static fn (object $row): string => self::str($row->carrier_id))->all()),
        );

        $views = [];
        foreach ($trips as $trip) {
            $tripId = self::str($trip->id);

            /** @var list<ShipmentShape> $load */
            $load = [];
            foreach ($loadByTrip[$tripId] ?? [] as $shipmentId) {
                $shipment = $shipments[$shipmentId] ?? null;
                if ($shipment === null) {
                    // Envío borrado de shipments: no se inventa; se omite de la carga visible.
                    continue;
                }
                $shipmentUnits = $units[$shipmentId] ?? [];
                $destinationId = $shipment['destination_node_id'];
                $load[] = [
                    'shipment_id' => $shipmentId,
                    'external_id' => $shipment['external_id'],
                    'order_reference' => $shipment['order_reference'],
                    'customer_name' => $shipment['customer_name'],
                    'destination_node' => $destinationId !== null ? ($nodeNames[$destinationId] ?? null) : null,
                    'destination_node_id' => $destinationId,
                    'units_count' => \count($shipmentUnits),
                    'units' => $shipmentUnits,
                ];
            }

            $from = self::str($trip->corridor_from);
            $to = self::str($trip->corridor_to);
            $carrierId = self::str($trip->carrier_id);

            $views[] = new CarrierAssignmentView(
                tripId: $tripId,
                carrierId: $carrierId,
                carrierName: $carrierNames[$carrierId] ?? null,
                reference: self::nullableStr($trip->reference),
                corridorFrom: $nodeNames[$from] ?? $from,
                corridorFromNodeId: $from,
                corridorTo: $nodeNames[$to] ?? $to,
                corridorToNodeId: $to,
                scheduledAt: self::iso($trip->scheduled_at) ?? '',
                status: self::str($trip->status),
                startedAt: self::iso($trip->started_at),
                closedAt: self::iso($trip->closed_at),
                shipments: $load,
            );
        }

        return $views;
    }

    public function count(?string $carrierId, array $statuses): int
    {
        return $this->filtered($carrierId, $statuses)->count();
    }

    public function existingShipmentIds(array $shipmentIds): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($shipmentIds)), self::CHUNK) as $chunk) {
            foreach (DB::table('shipments')->whereIn('id', $chunk)->pluck('id') as $id) {
                $found[] = self::str($id);
            }
        }

        return $found;
    }

    /**
     * @param list<string> $statuses
     */
    private function filtered(?string $carrierId, array $statuses): Builder
    {
        $query = DB::table('carrier_trips');

        if ($carrierId !== null && $carrierId !== '') {
            $query->where('carrier_id', $carrierId);
        }
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query;
    }

    /**
     * Cabeceras de envío por id.
     *
     * @param  list<string>                                                                                                                     $shipmentIds
     * @return array<string, array{external_id: string, order_reference: string|null, customer_name: string, destination_node_id: string|null}>
     */
    private function shipments(array $shipmentIds): array
    {
        $out = [];
        foreach (array_chunk($shipmentIds, self::CHUNK) as $chunk) {
            $rows = DB::table('shipments')
                ->whereIn('id', $chunk)
                ->get(['id', 'external_id', 'order_reference', 'customer_name', 'destination_node_id']);
            foreach ($rows as $row) {
                $out[self::str($row->id)] = [
                    'external_id' => self::str($row->external_id),
                    'order_reference' => self::nullableStr($row->order_reference),
                    'customer_name' => self::str($row->customer_name),
                    'destination_node_id' => self::nullableStr($row->destination_node_id),
                ];
            }
        }

        return $out;
    }

    /**
     * Productos físicos (unidades) de cada envío con nombre + SKU de su línea, en orden estable.
     *
     * @param  list<string>                   $shipmentIds
     * @return array<string, list<UnitShape>>
     */
    private function units(array $shipmentIds): array
    {
        $out = [];
        foreach (array_chunk($shipmentIds, self::CHUNK) as $chunk) {
            $rows = DB::table('shipment_units as u')
                ->leftJoin('shipment_lines as l', 'l.id', '=', 'u.line_id')
                ->whereIn('u.shipment_id', $chunk)
                ->orderBy('u.shipment_id')
                ->orderBy('l.created_at')
                ->orderBy('u.line_id')
                ->orderBy('u.unit_index')
                ->orderBy('u.piece_index')
                ->get(['u.id', 'u.shipment_id', 'u.piece_index', 'u.piece_total', 'l.sku', 'l.product_name']);

            foreach ($rows as $row) {
                $out[self::str($row->shipment_id)][] = self::unitShape($row);
            }
        }

        return $out;
    }

    /**
     * Forma primitiva de un producto físico (unidad + línea): nombre + SKU siempre, nunca vacío.
     *
     * @param  object{id: mixed, sku: mixed, product_name: mixed, piece_index: mixed, piece_total: mixed} $row
     * @return UnitShape
     */
    private static function unitShape(object $row): array
    {
        $sku = trim(self::str($row->sku));
        $name = trim(self::str($row->product_name));
        if ($name === '') {
            $name = $sku !== '' ? $sku : self::PRODUCT_FALLBACK;
        }
        $pieceIndex = (int) $row->piece_index;
        $pieceTotal = max(1, (int) $row->piece_total);

        $label = $sku !== '' && $sku !== $name ? "{$name} · {$sku}" : $name;
        if ($pieceTotal > 1) {
            $label .= " ({$pieceIndex} de {$pieceTotal})";
        }

        return [
            'unit_id' => self::str($row->id),
            'sku' => $sku,
            'product_name' => $name,
            'product_label' => $label,
            'piece_index' => $pieceIndex,
            'piece_total' => $pieceTotal,
        ];
    }

    /**
     * Nombre legible de nodos. Acepta id (UUID) o código de nodo: el corredor puede guardarse por cualquiera.
     *
     * @param  list<string>          $refs
     * @return array<string, string> ref → nombre
     */
    private function nodeNames(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
        if ($refs === []) {
            return [];
        }

        $uuids = array_values(array_filter($refs, static fn (string $ref): bool => self::isUuid($ref)));

        $out = [];
        foreach (array_chunk($refs, self::CHUNK) as $chunk) {
            foreach (DB::table('network_nodes')->whereIn('code', $chunk)->get(['code', 'name']) as $row) {
                $out[self::str($row->code)] = self::str($row->name);
            }
        }
        // Por id solo los que parecen UUID (comparar texto arbitrario contra una columna uuid falla en pgsql).
        foreach (array_chunk($uuids, self::CHUNK) as $chunk) {
            foreach (DB::table('network_nodes')->whereIn('id', $chunk)->get(['id', 'name']) as $row) {
                $out[self::str($row->id)] = self::str($row->name);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>          $carrierIds
     * @return array<string, string>
     */
    private function carrierNames(array $carrierIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($carrierIds)), self::CHUNK) as $chunk) {
            foreach (DB::table('carriers')->whereIn('id', $chunk)->get(['id', 'name']) as $row) {
                $out[self::str($row->id)] = self::str($row->name);
            }
        }

        return $out;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function str(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        $value = self::str($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Timestamp de la base → ISO 8601 (ATOM). Null si viene vacío o no se puede interpretar.
     */
    private static function iso(mixed $value): ?string
    {
        $value = self::str($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
