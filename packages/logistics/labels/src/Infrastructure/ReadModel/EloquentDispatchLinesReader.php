<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Logistics\Labels\Application\Contract\DispatchLinesReader;
use Logistics\Labels\Application\ReadModel\DispatchLinesView;
use Logistics\Labels\Application\ReadModel\DispatchLineView;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Throwable;

/**
 * Lee las LÍNEAS DE DESPACHO de una orden componiendo, en SOLO lectura y tolerante, varios contextos:
 *
 *  - Líneas y producto: `shipment_lines` del envío de la orden (external_id = orderNumber); nombre/imagen
 *    por SKU con fallback a catálogo (`commerce_products`). `line_number` = posición 1-based de la línea.
 *  - Destino: del envío (nodo store/warehouse o domicilio home), POR LÍNEA (hoy único por orden).
 *  - Autorización: el envío tiene parada en un plan PUBLICADO (`planning_plan_stops` × `planning_plans`).
 *  - Estado de etiqueta y origen: de las etiquetas emitidas de la orden (dominio labels). `label_status`
 *    = emitted (hay etiqueta vigente) / revoked (solo revocadas) / pending (ninguna). El origen humano
 *    sale del `org` de la etiqueta emitida (resuelto a nombre de nodo); en líneas pending, origen vacío.
 *
 * Cada fuente va en su try/catch: si una tabla/contexto falta, ese dato degrada a null/[] sin romper.
 */
final class EloquentDispatchLinesReader implements DispatchLinesReader
{
    /** @var array<string, string> network_nodes.type → {store|warehouse} */
    private const NODE_TYPE_MAP = [
        'distribution_center' => 'warehouse',
        'central_warehouse' => 'warehouse',
        'store' => 'store',
        'pickup_point' => 'store',
    ];

    public function __construct(
        private readonly EmittedLabelRepository $labels,
    ) {}

    public function read(string $orderNumber): DispatchLinesView
    {
        $shipment = $this->shipmentForOrder($orderNumber);
        $destination = $this->resolveDestination($shipment);
        $shipmentId = $shipment !== null ? (string) $shipment->id : null;
        $shipmentRef = $shipment !== null && is_string($shipment->external_id) ? $shipment->external_id : null;
        $authorized = $shipmentId !== null && $this->shipmentInPublishedPlan($shipmentId);
        $authorizedReason = $authorized ? null : 'sin plan publicado';

        $rows = $shipmentId !== null ? $this->shipmentLines($shipmentId) : [];
        $skus = array_values(array_unique(array_map(static fn (array $r): string => $r['sku'], $rows)));
        $catalog = $this->catalogProducts($skus);
        $labelsByKey = $this->labelsByKey($orderNumber);
        $originNames = $this->nodeNamesByCode(array_values(array_unique(array_filter(
            array_map(static fn (array $l): string => $l['origin_code'], $labelsByKey),
            static fn (string $c): bool => $c !== '',
        ))));

        $lines = [];
        $lineNumber = 0;
        foreach ($rows as $row) {
            $lineNumber++;
            $sku = $row['sku'];
            $key = $sku.'|'.$lineNumber;
            $label = $labelsByKey[$key] ?? null;

            $cat = $catalog[$sku] ?? null;
            $name = ($row['name'] ?? null) ?: ($cat['name'] ?? null);
            $image = ($row['image'] ?? null) ?: ($cat['image'] ?? null);

            $originCode = $label['origin_code'] ?? '';
            $originName = $originCode !== '' ? ($originNames[$originCode] ?? null) : null;

            $lines[] = new DispatchLineView(
                sku: $sku,
                productName: $name !== '' ? $name : null,
                imageUrl: $image !== '' ? $image : null,
                quantity: $row['quantity'],
                piecesPerUnit: 1,
                lineNumber: $lineNumber,
                originCode: $originCode,
                originName: $originName,
                destinationType: $destination['type'],
                destinationName: $destination['name'],
                destinationAddress: $destination['address'],
                shipmentId: $shipmentId,
                shipmentReference: $shipmentRef,
                authorized: $authorized,
                authorizedReason: $authorizedReason,
                labelStatus: $label['status'] ?? 'pending',
            );
        }

        $destinations = $lines === [] ? [] : [[
            'shipment_reference' => $shipmentRef,
            'destination' => $destination,
            'line_count' => count($lines),
        ]];

        return new DispatchLinesView($orderNumber, $destinations, $lines);
    }

    /** Envío más reciente de la orden (por external_id), o null. */
    private function shipmentForOrder(string $orderNumber): ?\stdClass
    {
        try {
            return DB::table('shipments')
                ->where('external_id', $orderNumber)
                ->orderByDesc('created_at')
                ->first(['id', 'external_id', 'destination_node_id', 'customer_name', 'address_text', 'address_reference']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Líneas del envío (sku, nombre, imagen servible, cantidad), en orden estable.
     *
     * @return list<array{sku: string, name: string|null, image: string|null, quantity: int}>
     */
    private function shipmentLines(string $shipmentId): array
    {
        try {
            $out = [];
            foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->orderBy('id')->get(['sku', 'product_name', 'product_image_url', 'quantity']) as $row) {
                $out[] = [
                    'sku' => (string) $row->sku,
                    'name' => is_string($row->product_name) && $row->product_name !== '' ? $row->product_name : null,
                    'image' => $this->toUrl(is_string($row->product_image_url) ? $row->product_image_url : null),
                    'quantity' => (int) $row->quantity,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Catálogo por SKU (fallback de nombre/imagen).
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
     * Mapa "sku|lineNumber" → {status, origin_code} desde las etiquetas emitidas de la orden.
     * status: emitted si hay una vigente; revoked si solo hay revocadas; (ausente = pending).
     *
     * @return array<string, array{status: string, origin_code: string}>
     */
    private function labelsByKey(string $orderNumber): array
    {
        try {
            $out = [];
            foreach ($this->labels->forOrder($orderNumber) as $label) {
                assert($label instanceof EmittedLabel);
                $key = $label->sku().'|'.$label->lineNumber();
                $existing = $out[$key] ?? null;

                $status = $label->isRevoked() ? 'revoked' : 'emitted';
                // Una etiqueta vigente manda sobre revocadas previas del mismo (sku, línea).
                if ($existing === null || ($existing['status'] === 'revoked' && $status === 'emitted')) {
                    $out[$key] = ['status' => $status, 'origin_code' => $label->originCode()];
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** ¿El envío tiene parada en un plan PUBLICADO? (autorización). */
    private function shipmentInPublishedPlan(string $shipmentId): bool
    {
        try {
            return DB::table('planning_plan_stops')
                ->join('planning_plans', 'planning_plans.id', '=', 'planning_plan_stops.plan_id')
                ->where('planning_plan_stops.shipment_id', $shipmentId)
                ->where('planning_plans.status', 'PUBLISHED')
                ->exists();
        } catch (Throwable) {
            return false;
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
                    return [
                        'type' => self::NODE_TYPE_MAP[(string) $node->type] ?? 'store',
                        'name' => is_string($node->name) ? $node->name : null,
                        'address' => null,
                    ];
                }
            } catch (Throwable) {
                // degrada a domicilio
            }
        }

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

    /** Convierte un path/URL en URL servible ABSOLUTA, o null. */
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
