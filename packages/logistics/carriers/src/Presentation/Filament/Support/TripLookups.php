<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Valores legibles para el admin de viajes de transportista (regla dura: nunca mostrar UUIDs).
 *
 * Lee en SOLO lectura tablas de otros contextos (network_nodes, shipments, shipment_lines/units) por
 * query builder, sin importar sus clases (contexto desacoplable). Los mapas de nodos se memoizan por
 * request para no disparar N+1 al pintar tablas e infolists; las búsquedas de envíos van acotadas a 50.
 */
final class TripLookups
{
    /**
     * Estados vigentes de un viaje (mismos valores que CarrierTripStatus::activeValues() del dominio,
     * replicados para que Presentation no dependa del Domain).
     */
    private const ACTIVE_STATUSES = ['scheduled', 'in_progress'];

    /** @var array<string, string>|null Mapa memoizado id→nombre de nodos. */
    private static ?array $nodes = null;

    /**
     * Opciones de nodo (id → "Nombre (CÓDIGO)") para los Selects de corredor.
     *
     * @return array<string, string>
     */
    public static function nodeOptions(): array
    {
        $out = [];
        foreach (DB::table('network_nodes')->orderBy('name')->get(['id', 'code', 'name']) as $row) {
            $out[self::str($row->id)] = self::str($row->name).' ('.self::str($row->code).')';
        }

        return $out;
    }

    /**
     * Nombre legible de un nodo por id o código; si no se encuentra, el valor tal cual (nunca vacío).
     */
    public static function nodeName(?string $ref): string
    {
        if ($ref === null || $ref === '') {
            return '—';
        }

        if (self::$nodes === null) {
            $map = [];
            foreach (DB::table('network_nodes')->get(['id', 'code', 'name']) as $row) {
                $map[self::str($row->id)] = self::str($row->name);
                $map[self::str($row->code)] = self::str($row->name);
            }
            self::$nodes = $map;
        }

        return self::$nodes[$ref] ?? $ref;
    }

    /**
     * Búsqueda de envíos para cargar en un viaje: por número de pedido/envío o cliente, excluyendo los que
     * ya están en un viaje vigente. Etiqueta legible, nunca el UUID.
     *
     * @return array<string, string>
     */
    public static function searchLoadableShipments(string $search): array
    {
        $term = '%'.trim($search).'%';

        $rows = DB::table('shipments')
            ->whereNotIn('id', static function (Builder $query): void {
                $query->select('s.shipment_id')
                    ->from('carrier_trip_shipments as s')
                    ->join('carrier_trips as t', 't.id', '=', 's.trip_id')
                    ->whereIn('t.status', self::ACTIVE_STATUSES);
            })
            ->where(static function (Builder $query) use ($term): void {
                $query->where('external_id', 'ilike', $term)
                    ->orWhere('order_reference', 'ilike', $term)
                    ->orWhere('customer_name', 'ilike', $term);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'external_id', 'order_reference', 'customer_name']);

        $out = [];
        foreach ($rows as $row) {
            $out[self::str($row->id)] = self::shipmentLabel($row);
        }

        return $out;
    }

    /**
     * Etiquetas legibles de envíos por id (en lote).
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    public static function shipmentLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('shipments')->whereIn('id', $chunk)->get(['id', 'external_id', 'order_reference', 'customer_name']) as $row) {
                $out[self::str($row->id)] = self::shipmentLabel($row);
            }
        }

        return $out;
    }

    /**
     * Carga de un viaje para el infolist: un renglón por envío con pedido, cliente, destino y productos
     * (nombre + SKU). Tres consultas fijas, sin N+1.
     *
     * @return list<array{shipment: string, customer: string, destination: string, products: string, units: int}>
     */
    public static function load(string $tripId): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_map(
            static fn (mixed $id): string => self::str($id),
            DB::table('carrier_trip_shipments')->where('trip_id', $tripId)->orderBy('position')->pluck('shipment_id')->all(),
        ));
        if ($ids === []) {
            return [];
        }

        $shipments = [];
        foreach (DB::table('shipments')->whereIn('id', $ids)->get(['id', 'external_id', 'order_reference', 'customer_name', 'destination_node_id']) as $row) {
            $shipments[self::str($row->id)] = $row;
        }

        /** @var array<string, array<string, array{label: string, count: int}>> $products */
        $products = [];
        $units = DB::table('shipment_units as u')
            ->leftJoin('shipment_lines as l', 'l.id', '=', 'u.line_id')
            ->whereIn('u.shipment_id', $ids)
            ->get(['u.shipment_id', 'l.sku', 'l.product_name']);
        foreach ($units as $unit) {
            $sku = trim(self::str($unit->sku));
            $name = trim(self::str($unit->product_name));
            if ($name === '') {
                $name = $sku !== '' ? $sku : 'Product';
            }
            $label = $sku !== '' && $sku !== $name ? "{$name} · {$sku}" : $name;
            $shipmentId = self::str($unit->shipment_id);
            $products[$shipmentId][$label] ??= ['label' => $label, 'count' => 0];
            $products[$shipmentId][$label]['count']++;
        }

        $out = [];
        foreach ($ids as $id) {
            $row = $shipments[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $lines = array_map(
                static fn (array $p): string => $p['count'] > 1 ? "{$p['label']} ×{$p['count']}" : $p['label'],
                array_values($products[$id] ?? []),
            );
            $destination = self::str($row->destination_node_id);
            $out[] = [
                'shipment' => self::shipmentLabel($row),
                'customer' => self::str($row->customer_name),
                'destination' => $destination !== '' ? self::nodeName($destination) : __('Customer address'),
                'products' => $lines !== [] ? implode(', ', $lines) : __('No products'),
                'units' => array_sum(array_map(static fn (array $p): int => $p['count'], $products[$id] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * "Pedido · Cliente" con el número de pedido (order_reference) o, si no hay, el id externo del envío.
     */
    private static function shipmentLabel(object $row): string
    {
        $order = self::str($row->order_reference ?? null);
        $external = self::str($row->external_id ?? null);
        $number = $order !== '' ? $order : $external;
        $customer = self::str($row->customer_name ?? null);

        return $customer !== '' ? "{$number} · {$customer}" : $number;
    }

    private static function str(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_scalar($value) ? (string) $value : '';
    }
}
