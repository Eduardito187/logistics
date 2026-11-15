<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\ReadModel\ShipmentPlanningFacts;
use Logistics\Planning\Infrastructure\Adapter\ShipmentLoadReader;
use Logistics\Shipments\Application\ReadModel\AddressPartsView;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Throwable;

/**
 * Adaptador read-side de los datos de envíos que usa Planificación, por query builder sobre tablas de
 * Envíos y Red (solo lectura, sin modelos ni escritura). Cada lectura va EN LOTE por bloques de ids:
 *
 *  - una consulta sobre `shipments` ⨝ `network_nodes` (pedido, cliente, dirección, hora elegida, ubicación,
 *    departamento y tipo del nodo destino);
 *  - una consulta agregada sobre `shipment_units` (unidades por envío) y, solo para los envíos que todavía
 *    no tienen unidades generadas, otra sobre `shipment_lines` (suma de cantidades);
 *  - opcionalmente la carga (kg / m3) con el lector de carga del contexto.
 *
 * Los criterios NO se reimplementan: la dirección corta es la de Envíos ({@see AddressPartsView}), la
 * modalidad de entrega sale de {@see DeliveryMode} / {@see OrderSource} y el departamento se normaliza con
 * {@see DepartmentCode}.
 *
 * Ubicación: la del envío; para un recojo en tienda o un traspaso sin coordenadas propias, la del nodo
 * destino (ahí descarga el camión). Un 0,0 se trata como "sin ubicación".
 *
 * Degradación con gracia: si una lectura falla devuelve vacío (el plan se muestra igual, sin los datos
 * legibles); dentro de una transacción corre en un savepoint para no abortar la transacción externa.
 */
final readonly class DbShipmentPlanningFactsReader implements ShipmentPlanningFactsReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tope de ids por consulta whereIn. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private ConnectionInterface $connection,
        private ShipmentLoadReader $loads,
    ) {}

    public function factsFor(array $shipmentIds, bool $withLoad = false): array
    {
        $ids = [];
        foreach ($shipmentIds as $id) {
            // Nunca se compara contra una columna uuid un valor que no tenga forma de UUID (22P02).
            if (preg_match(self::UUID_PATTERN, $id) === 1) {
                $ids[strtolower($id)] = true;
            }
        }
        $ids = array_map(strval(...), array_keys($ids));
        if ($ids === []) {
            return [];
        }

        $facts = [];
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            foreach ($this->safely(fn (): array => $this->read($chunk, $withLoad)) as $shipmentId => $fact) {
                $facts[$shipmentId] = $fact;
            }
        }

        return $facts;
    }

    /**
     * @param  list<string>                         $ids
     * @return array<string, ShipmentPlanningFacts>
     */
    private function read(array $ids, bool $withLoad): array
    {
        $rows = $this->connection->table('shipments as s')
            ->leftJoin('network_nodes as n', 'n.id', '=', 's.destination_node_id')
            ->whereIn('s.id', $ids)
            ->get([
                's.id', 's.source', 's.external_id', 's.order_reference', 's.customer_name', 's.address_text',
                's.address_reference', 's.lat', 's.lng', 's.destination_node_id', 's.destination_department',
                's.delivery_window_start', 's.delivery_window_end',
                'n.type as node_type', 'n.lat as node_lat', 'n.lng as node_lng',
            ])
            ->all();
        if ($rows === []) {
            return [];
        }

        $found = [];
        foreach ($rows as $row) {
            $id = self::text(((array) $row)['id'] ?? null);
            if ($id !== null) {
                $found[] = strtolower($id);
            }
        }

        $units = $this->unitTotals($found);
        $loads = $withLoad ? $this->loads->loadsFor($found) : [];

        $facts = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = self::text($data['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $id = strtolower($id);

            $mode = $this->deliveryMode($data);
            [$lat, $lng] = $this->location($data, $mode);
            $address = self::text($data['address_text'] ?? null);

            $facts[$id] = new ShipmentPlanningFacts(
                shipmentId: $id,
                orderReference: self::text($data['order_reference'] ?? null) ?? self::text($data['external_id'] ?? null),
                customerName: self::text($data['customer_name'] ?? null),
                address: $address,
                shortAddress: $address === null
                    ? null
                    : AddressPartsView::fromText($address, self::text($data['address_reference'] ?? null))->shortAddress,
                deliveryMode: $mode->value,
                unitsTotal: $units[$id] ?? 0,
                windowStart: self::text($data['delivery_window_start'] ?? null),
                windowEnd: self::text($data['delivery_window_end'] ?? null),
                latitude: $lat,
                longitude: $lng,
                department: DepartmentCode::of(self::text($data['destination_department'] ?? null)),
                weightKg: $loads[$id]['kg'] ?? 0.0,
                volumeM3: $loads[$id]['m3'] ?? 0.0,
            );
        }

        return $facts;
    }

    /**
     * Modalidad de entrega DERIVADA con la regla de Envíos: sin nodo destino = domicilio; traspaso interno
     * con nodo destino = traspaso; si no, según el tipo del nodo (tienda / punto de recojo = recojo).
     *
     * @param array<array-key, mixed> $data
     */
    private function deliveryMode(array $data): DeliveryMode
    {
        if (self::text($data['destination_node_id'] ?? null) === null) {
            return DeliveryMode::HomeDelivery;
        }

        $source = self::text($data['source'] ?? null);
        if ($source !== null && OrderSource::tryFromLoose($source)?->isInternalTransfer() === true) {
            return DeliveryMode::Transfer;
        }

        return DeliveryMode::forDestinationNodeType(self::text($data['node_type'] ?? null));
    }

    /**
     * Ubicación de entrega: la del envío y, solo si el camión descarga en un nodo (recojo en tienda o
     * traspaso), la del nodo destino como respaldo. Un reparto a domicilio nunca toma la del nodo.
     *
     * @param  array<array-key, mixed>             $data
     * @return array{0: float|null, 1: float|null}
     */
    private function location(array $data, DeliveryMode $mode): array
    {
        $point = self::coordinates($data['lat'] ?? null, $data['lng'] ?? null);
        if ($point === null && $mode !== DeliveryMode::HomeDelivery) {
            $point = self::coordinates($data['node_lat'] ?? null, $data['node_lng'] ?? null);
        }

        return $point ?? [null, null];
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private static function coordinates(mixed $lat, mixed $lng): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $latitude = (float) $lat;
        $longitude = (float) $lng;
        $outOfRange = $latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0;
        if ($outOfRange || ($latitude === 0.0 && $longitude === 0.0)) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Unidades por envío: las unidades físicas generadas y, para los envíos que todavía no las tienen, la
     * suma de cantidades de sus líneas.
     *
     * @param  list<string>       $ids
     * @return array<string, int>
     */
    private function unitTotals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $totals = [];
        $rows = $this->connection->table('shipment_units')
            ->whereIn('shipment_id', $ids)
            ->groupBy('shipment_id')
            ->selectRaw('shipment_id, COUNT(*) AS total')
            ->get();
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = self::text($data['shipment_id'] ?? null);
            if ($id !== null && is_numeric($data['total'] ?? null)) {
                $totals[strtolower($id)] = (int) $data['total'];
            }
        }

        $missing = array_values(array_filter($ids, static fn (string $id): bool => !isset($totals[$id])));
        if ($missing === []) {
            return $totals;
        }

        $rows = $this->connection->table('shipment_lines')
            ->whereIn('shipment_id', $missing)
            ->groupBy('shipment_id')
            ->selectRaw('shipment_id, COALESCE(SUM(quantity), 0) AS total')
            ->get();
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = self::text($data['shipment_id'] ?? null);
            if ($id !== null && is_numeric($data['total'] ?? null)) {
                $totals[strtolower($id)] = (int) $data['total'];
            }
        }

        return $totals;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Lectura tolerante: ante error devuelve vacío. Dentro de una transacción abierta corre en un
     * savepoint, así un fallo no deja abortada la transacción externa (PostgreSQL).
     *
     * @param  callable(): array<string, ShipmentPlanningFacts> $read
     * @return array<string, ShipmentPlanningFacts>
     */
    private function safely(callable $read): array
    {
        try {
            if ($this->connection->transactionLevel() > 0) {
                /** @var array<string, ShipmentPlanningFacts> $result */
                $result = $this->connection->transaction(static fn (): array => $read());

                return $result;
            }

            return $read();
        } catch (Throwable) {
            return [];
        }
    }
}
