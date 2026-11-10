<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Logistics\Labels\Application\Contract\LabelEnricher;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Throwable;

/**
 * Enriquecedor de etiquetas por LECTURA CRUZADA (solo lectura, tolerante), sin acoplar el dominio de
 * labels a otros contextos (mismo patrón que los readers cross-context del proyecto):
 *
 *  - Producto (nombre + imagen): por SKU. Fuente primaria `shipment_lines` del envío de la orden
 *    (denormalizado por orden); fallback al catálogo `commerce_products.canonical_sku` (name + media).
 *  - Destino: del envío de la orden (`shipments.external_id` = orderNumber). Con `destination_node_id`
 *    → nodo de red (tipo store/warehouse + nombre); sin él → domicilio (home) con cliente + dirección.
 *  - Origen: el código `org` del QR resuelto a nombre de nodo (`network_nodes.code`) si matchea; si no,
 *    queda solo el código (la app muestra name ?? code).
 *
 * Cada fuente va en su try/catch: si una tabla/contexto no está disponible, ese campo degrada a null y el
 * resto se resuelve igual. `image_url` se devuelve como URL servible ABSOLUTA.
 */
final class EloquentLabelEnricher implements LabelEnricher
{
    /** @var array<string, string> distribution_center/central_warehouse → warehouse; store/pickup_point → store. */
    private const NODE_TYPE_MAP = [
        'distribution_center' => 'warehouse',
        'central_warehouse' => 'warehouse',
        'store' => 'store',
        'pickup_point' => 'store',
    ];

    public function enrich(string $orderNumber, array $views): array
    {
        if ($views === []) {
            return $views;
        }

        // Multishipping: una orden puede tener varios envíos hermanos (uno por destino). Se arma el
        // contexto de CADA hermano (líneas por ordinal, bultos, destino) y cada etiqueta se resuelve contra
        // el hermano que realmente la contiene (el guard ordinal+sku la ata a su envío de emisión).
        $siblings = [];
        $linesBySku = [];
        foreach ($this->shipmentsForOrder($orderNumber) as $shipment) {
            $sid = (string) $shipment->id;
            $siblings[] = [
                'id' => $sid,
                'lineByOrdinal' => $this->lineByOrdinal($sid),
                'unitsByKey' => $this->unitsByKey($sid),
                'destination' => $this->resolveDestination($shipment),
            ];
            // Nombre/imagen por sku: unión de hermanos (un producto trae el mismo nombre en cualquiera).
            $linesBySku += $this->shipmentLines($sid);
        }

        $skus = array_values(array_unique(array_map(static fn (EmittedLabelView $v): string => $v->sku, $views)));
        $catalogBySku = $this->catalogProducts($skus);

        $originCodes = array_values(array_filter(array_unique(array_map(
            static fn (EmittedLabelView $v): string => $v->originCode,
            $views,
        )), static fn (string $c): bool => $c !== ''));
        $originNames = $this->nodeNamesByCode($originCodes);

        // Por defecto el primer hermano (preserva el flujo de un solo envío, aun sin líneas que casen).
        $firstSibling = $siblings[0] ?? null;
        $emptyDestination = $this->resolveDestination(null);

        $out = [];
        foreach ($views as $view) {
            $sku = $view->sku;
            $line = $linesBySku[$sku] ?? null;
            $catalog = $catalogBySku[$sku] ?? null;

            $name = ($line['name'] ?? null) ?: ($catalog['name'] ?? null);
            $image = ($line['image'] ?? null) ?: ($catalog['image'] ?? null);
            $originName = $originNames[$view->originCode] ?? null;

            // Puente etiqueta→bulto (offline-first): la N-ésima línea del hermano, SOLO si su sku coincide
            // con el de la etiqueta (guard contra desalineación emisión↔ingreso); luego el bulto por
            // unit_index + piece_index. El hermano que resuelve el bulto fija también el destino/envío.
            $unitId = null;
            $shipmentId = $firstSibling['id'] ?? null;
            $destination = $firstSibling['destination'] ?? $emptyDestination;
            $skuFallback = null;
            foreach ($siblings as $sibling) {
                $ordinalLine = $sibling['lineByOrdinal'][$view->lineNumber] ?? null;
                $containsSku = $ordinalLine !== null && $ordinalLine['sku'] === $sku;
                if ($containsSku && $skuFallback === null) {
                    $skuFallback = $sibling;
                }
                if ($containsSku) {
                    $candidate = $sibling['unitsByKey'][$ordinalLine['id'].'|'.$view->unitIndex.'|'.$view->pieceIndex] ?? null;
                    if ($candidate !== null) {
                        $unitId = $candidate;
                        $shipmentId = $sibling['id'];
                        $destination = $sibling['destination'];
                        break;
                    }
                }
            }
            // Sin bulto resuelto: el destino/envío cae al hermano que contiene el sku (o queda vacío).
            if ($shipmentId === null && $skuFallback !== null) {
                $shipmentId = $skuFallback['id'];
                $destination = $skuFallback['destination'];
            }

            $out[] = $view->enriched(
                productName: $name !== '' ? $name : null,
                imageUrl: $image !== '' ? $image : null,
                originName: $originName,
                destination: $destination,
                unitId: $unitId,
                shipmentId: $shipmentId,
            );
        }

        return $out;
    }

    /**
     * Línea (id + sku) por ordinal 1-based en el envío (el lineNumber de la etiqueta es esa posición).
     *
     * @return array<int, array{id: string, sku: string}>
     */
    private function lineByOrdinal(string $shipmentId): array
    {
        try {
            $out = [];
            $n = 0;
            foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->orderBy('id')->get(['id', 'sku']) as $line) {
                $n++;
                $out[$n] = ['id' => (string) $line->id, 'sku' => (string) $line->sku];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * unit_id por clave "{line_id}|{unit_index}|{piece_index}" (el bulto físico).
     *
     * @return array<string, string>
     */
    private function unitsByKey(string $shipmentId): array
    {
        try {
            $out = [];
            foreach (DB::table('shipment_units')->where('shipment_id', $shipmentId)->get(['id', 'line_id', 'unit_index', 'piece_index']) as $u) {
                $out[((string) $u->line_id).'|'.((int) $u->unit_index).'|'.((int) $u->piece_index)] = (string) $u->id;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Envíos hermanos de la orden (por order_reference; cae a external_id para filas previas al backfill),
     * en orden estable. Multishipping: puede devolver más de uno (uno por destino).
     *
     * @return list<\stdClass>
     */
    private function shipmentsForOrder(string $orderNumber): array
    {
        try {
            return array_values(DB::table('shipments')
                ->where('order_reference', $orderNumber)
                ->orWhere('external_id', $orderNumber)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'destination_node_id', 'customer_name', 'address_text', 'address_reference'])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Mapa sku → {name, image(url servible)} desde shipment_lines del envío.
     *
     * @return array<string, array{name: string|null, image: string|null}>
     */
    private function shipmentLines(string $shipmentId): array
    {
        try {
            $out = [];
            foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->get(['sku', 'product_name', 'product_image_url']) as $row) {
                $out[(string) $row->sku] = [
                    'name' => is_string($row->product_name) && $row->product_name !== '' ? $row->product_name : null,
                    'image' => $this->toUrl(is_string($row->product_image_url) ? $row->product_image_url : null),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Mapa sku → {name, image(url servible)} desde el catálogo (commerce_products.canonical_sku).
     *
     * @param  list<string>                                                $skus
     * @return array<string, array{name: string|null, image: string|null}>
     */
    private function catalogProducts(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            $out = [];
            foreach (DB::table('commerce_products')->whereIn('canonical_sku', $skus)->get(['canonical_sku', 'name', 'media']) as $row) {
                $out[(string) $row->canonical_sku] = [
                    'name' => is_string($row->name) && $row->name !== '' ? $row->name : null,
                    'image' => $this->firstMediaUrl(is_string($row->media) ? $row->media : null),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Destino del envío: nodo (store|warehouse) o domicilio (home). Vacío si no hay envío.
     *
     * @return array{type: string|null, name: string|null, address: string|null}
     */
    private function resolveDestination(?\stdClass $shipment): array
    {
        $empty = ['type' => null, 'name' => null, 'address' => null];
        if ($shipment === null) {
            return $empty;
        }

        $nodeId = is_string($shipment->destination_node_id) ? $shipment->destination_node_id : null;

        if ($nodeId !== null && $nodeId !== '') {
            try {
                $node = DB::table('network_nodes')->where('id', $nodeId)->first(['name', 'type']);
                if ($node !== null) {
                    $type = self::NODE_TYPE_MAP[(string) $node->type] ?? 'store';

                    return [
                        'type' => $type,
                        'name' => is_string($node->name) ? $node->name : null,
                        'address' => null,
                    ];
                }
            } catch (Throwable) {
                // degrada a domicilio
            }
        }

        // Domicilio.
        $address = is_string($shipment->address_text) && $shipment->address_text !== '' ? $shipment->address_text : null;
        $reference = is_string($shipment->address_reference) && $shipment->address_reference !== '' ? $shipment->address_reference : null;
        if ($address !== null && $reference !== null) {
            $address .= ' ('.$reference.')';
        }

        return [
            'type' => 'home',
            'name' => is_string($shipment->customer_name) && $shipment->customer_name !== '' ? $shipment->customer_name : null,
            'address' => $address,
        ];
    }

    /**
     * Nombres de nodo por código, en lote.
     *
     * @param  list<string>          $codes
     * @return array<string, string>
     */
    private function nodeNamesByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        try {
            /** @var array<string, string> $names */
            $names = DB::table('network_nodes')->whereIn('code', $codes)->pluck('name', 'code')->all();

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /** Primera imagen (menor position) del jsonb `media` del catálogo, como URL servible. */
    private function firstMediaUrl(?string $mediaJson): ?string
    {
        if ($mediaJson === null || $mediaJson === '') {
            return null;
        }

        try {
            /** @var mixed $media */
            $media = json_decode($mediaJson, true);
            if (!is_array($media) || $media === []) {
                return null;
            }

            usort($media, static fn ($a, $b): int => (is_array($a) ? (int) ($a['position'] ?? 0) : 0) <=> (is_array($b) ? (int) ($b['position'] ?? 0) : 0));
            $first = $media[0];
            $path = is_array($first) && isset($first['path']) && is_string($first['path']) ? $first['path'] : null;

            return $this->toUrl($path);
        } catch (Throwable) {
            return null;
        }
    }

    /** Convierte un path/URL de imagen en URL servible ABSOLUTA, o null. */
    private function toUrl(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        try {
            return Storage::disk('public')->url(ltrim($value, '/'));
        } catch (Throwable) {
            return null;
        }
    }
}
