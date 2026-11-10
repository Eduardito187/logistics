<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un producto del catálogo (commerce): agrega su ficha (nombre,
 * SKU canónico, estado, categoría, vendedor), sus VARIANTES, el INVENTARIO on-hand (contexto
 * logistics/inventory, `inventory_lots`), los PRECIOS vigentes (contexto commerce/pricing) y las ÓRDENES
 * recientes que lo contienen (commerce/orders, líneas en JSONB). El cruce entre contextos es por SKU
 * (canónico + de cada variante), que es la llave común del catálogo. SOLO lectura, tolerante (cada
 * sección en su try/catch: si un contexto no está disponible, su bloque queda vacío, no rompe la ficha).
 * Sin UUID visible: el producto es su nombre + SKU; las órdenes se identifican por el cliente + fecha.
 *
 * Patrón cockpit (igual que {@see SellerHub}/{@see CustomerHub}): forma primitiva (arrays), memoizado por
 * request. No se cachea porque el stock y las órdenes son datos vivos que el operador espera al día.
 */
final class ProductHub
{
    /**
     * Memo por request: product id => overview ya compuesto.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /**
     * Todo lo que la ficha 360 del producto necesita, en primitivos y tolerante.
     *
     * @return array{
     *   product: array{name: string, canonical_sku: string, status: string, category: ?string, seller: ?string, variants_count: int, media_count: int}|null,
     *   metrics: array{variants: int, stock_available: int, stock_reserved: int, orders: int, units_sold: int, currency: string},
     *   skus: list<array{sku: string, name: string, is_variant: bool, available: int, reserved: int}>,
     *   prices: list<array{sku: string, channel: string, list_name: string, amount_cents: int, currency: string, status: string}>,
     *   orders: list<array{customer: string, status: string, date: string, units: int, subtotal_cents: int, currency: string}>,
     * }
     */
    public function overview(string $productId): array
    {
        if (isset($this->memo[$productId])) {
            /** @var array{product: array{name: string, canonical_sku: string, status: string, category: ?string, seller: ?string, variants_count: int, media_count: int}|null, metrics: array{variants: int, stock_available: int, stock_reserved: int, orders: int, units_sold: int, currency: string}, skus: list<array{sku: string, name: string, is_variant: bool, available: int, reserved: int}>, prices: list<array{sku: string, channel: string, list_name: string, amount_cents: int, currency: string, status: string}>, orders: list<array{customer: string, status: string, date: string, units: int, subtotal_cents: int, currency: string}>} $cached */
            $cached = $this->memo[$productId];

            return $cached;
        }

        return $this->memo[$productId] = $this->compose($productId);
    }

    /**
     * Arma el overview desde cero (lo llama overview() una sola vez por producto y request).
     *
     * @return array{
     *   product: array{name: string, canonical_sku: string, status: string, category: ?string, seller: ?string, variants_count: int, media_count: int}|null,
     *   metrics: array{variants: int, stock_available: int, stock_reserved: int, orders: int, units_sold: int, currency: string},
     *   skus: list<array{sku: string, name: string, is_variant: bool, available: int, reserved: int}>,
     *   prices: list<array{sku: string, channel: string, list_name: string, amount_cents: int, currency: string, status: string}>,
     *   orders: list<array{customer: string, status: string, date: string, units: int, subtotal_cents: int, currency: string}>,
     * }
     */
    private function compose(string $productId): array
    {
        $row = $this->productRow($productId);

        if ($row === null) {
            return [
                'product' => null,
                'metrics' => ['variants' => 0, 'stock_available' => 0, 'stock_reserved' => 0, 'orders' => 0, 'units_sold' => 0, 'currency' => 'BOB'],
                'skus' => [],
                'prices' => [],
                'orders' => [],
            ];
        }

        $variants = $this->variants($row);
        // Llaves de cruce cross-context: SKU canónico + el de cada variante (en mayúsculas, sin vacíos).
        $skuList = $this->skuKeys((string) $row->canonical_sku, $variants);

        $stockBySku = $this->stockBySku($skuList);
        $prices = $this->prices($skuList);
        $orders = $this->orders($skuList);

        $skus = $this->skuRows((string) $row->canonical_sku, (string) $row->name, $variants, $stockBySku);

        $stockAvailable = 0;
        $stockReserved = 0;
        foreach ($stockBySku as $s) {
            $stockAvailable += $s['available'];
            $stockReserved += $s['reserved'];
        }

        $unitsSold = 0;
        foreach ($orders as $o) {
            $unitsSold += $o['units'];
        }

        $currency = $this->firstCurrency($prices, $orders);

        return [
            'product' => [
                'name' => (string) $row->name,
                'canonical_sku' => (string) $row->canonical_sku,
                'status' => (string) $row->status,
                'category' => $this->categoryName($this->str($row->category_id ?? null)),
                'seller' => $this->sellerName($this->str($row->seller_id ?? null)),
                'variants_count' => count($variants),
                'media_count' => $this->mediaCount($row),
            ],
            'metrics' => [
                'variants' => count($variants),
                'stock_available' => $stockAvailable,
                'stock_reserved' => $stockReserved,
                'orders' => count($orders),
                'units_sold' => $unitsSold,
                'currency' => $currency,
            ],
            'skus' => $skus,
            'prices' => $prices,
            'orders' => $orders,
        ];
    }

    /** Fila cruda del producto, o null si no existe. */
    private function productRow(string $productId): ?\stdClass
    {
        try {
            return DB::table('commerce_products')->where('id', $productId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Variantes del producto (JSONB `variants`): sku, nombre, atributos.
     *
     * @return list<array{sku: string, name: string, attributes: array<string, string>}>
     */
    private function variants(\stdClass $row): array
    {
        $raw = $row->variants ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $v) {
            if (!is_array($v)) {
                continue;
            }
            $sku = isset($v['sku']) && is_string($v['sku']) ? $v['sku'] : '';
            if ($sku === '') {
                continue;
            }
            /** @var array<string, string> $attrs */
            $attrs = [];
            if (isset($v['attributes']) && is_array($v['attributes'])) {
                foreach ($v['attributes'] as $k => $val) {
                    if (is_string($k) && is_scalar($val)) {
                        $attrs[$k] = (string) $val;
                    }
                }
            }
            $out[] = [
                'sku' => $sku,
                'name' => isset($v['name']) && is_string($v['name']) ? $v['name'] : $sku,
                'attributes' => $attrs,
            ];
        }

        return $out;
    }

    /** Cantidad de medios (JSONB `media`) del producto. */
    private function mediaCount(\stdClass $row): int
    {
        $raw = $row->media ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * Lista de SKUs de cruce (canónico + variantes), normalizados a MAYÚSCULAS (como los guarda OrderLine)
     * y sin duplicados ni vacíos.
     *
     * @param  list<array{sku: string, name: string, attributes: array<string, string>}> $variants
     * @return list<string>
     */
    private function skuKeys(string $canonical, array $variants): array
    {
        $keys = [];
        foreach (array_merge([$canonical], array_map(static fn (array $v): string => $v['sku'], $variants)) as $sku) {
            $norm = strtoupper(trim($sku));
            if ($norm !== '') {
                $keys[$norm] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Filas de SKU para la tabla de la ficha: canónico primero, luego variantes, cada uno con su stock.
     *
     * @param  list<array{sku: string, name: string, attributes: array<string, string>}>               $variants
     * @param  array<string, array{available: int, reserved: int}>                                     $stockBySku
     * @return list<array{sku: string, name: string, is_variant: bool, available: int, reserved: int}>
     */
    private function skuRows(string $canonical, string $productName, array $variants, array $stockBySku): array
    {
        $rows = [];
        $canonKey = strtoupper(trim($canonical));
        if ($canonKey !== '') {
            $rows[] = [
                'sku' => $canonical,
                'name' => $productName,
                'is_variant' => false,
                'available' => $stockBySku[$canonKey]['available'] ?? 0,
                'reserved' => $stockBySku[$canonKey]['reserved'] ?? 0,
            ];
        }

        foreach ($variants as $v) {
            $key = strtoupper(trim($v['sku']));
            $rows[] = [
                'sku' => $v['sku'],
                'name' => $v['name'],
                'is_variant' => true,
                'available' => $stockBySku[$key]['available'] ?? 0,
                'reserved' => $stockBySku[$key]['reserved'] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Inventario on-hand por SKU (contexto logistics/inventory, `inventory_lots`): suma disponible y
     * reservado de todos los lotes de todos los almacenes. Clave en MAYÚSCULAS para cruzar con skuKeys.
     *
     * @param  list<string>                                        $skus
     * @return array<string, array{available: int, reserved: int}>
     */
    private function stockBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            $out = [];
            $rows = DB::table('inventory_lots')
                ->whereIn(DB::raw('upper(sku)'), $skus)
                ->selectRaw('upper(sku) as sku, coalesce(sum(available_quantity), 0) as available, coalesce(sum(reserved_quantity), 0) as reserved')
                ->groupBy(DB::raw('upper(sku)'))
                ->get();

            foreach ($rows as $r) {
                $out[(string) $r->sku] = [
                    'available' => (int) $r->available,
                    'reserved' => (int) $r->reserved,
                ];
            }

            return $out;
        } catch (Throwable) {
            // sin contexto de inventario disponible: la ficha muestra 0 (honesto, no inventa).
            return [];
        }
    }

    /**
     * Precios vigentes por SKU (contexto commerce/pricing): item de lista + su lista (nombre/canal/estado).
     * Solo listas activas; ordenado por canal.
     *
     * @param  list<string>                                                                                                      $skus
     * @return list<array{sku: string, channel: string, list_name: string, amount_cents: int, currency: string, status: string}>
     */
    private function prices(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            return array_values(DB::table('commerce_price_list_items as i')
                ->join('commerce_price_lists as l', 'l.id', '=', 'i.price_list_id')
                ->whereIn(DB::raw('upper(i.sku)'), $skus)
                ->orderBy('l.channel')
                ->orderBy('i.sku')
                ->limit(50)
                ->get(['i.sku', 'i.gross_amount_cents', 'i.currency', 'l.name as list_name', 'l.channel', 'l.status'])
                ->map(static fn ($r): array => [
                    'sku' => (string) $r->sku,
                    'channel' => (string) $r->channel,
                    'list_name' => (string) $r->list_name,
                    'amount_cents' => (int) $r->gross_amount_cents,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                    'status' => (string) $r->status,
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Órdenes recientes que contienen el producto (commerce/orders, líneas en JSONB): cruza por SKU con
     * un lateral sobre `jsonb_array_elements(lines)`. La orden se identifica por el cliente (snapshot de
     * ship_to) + fecha, nunca por UUID. `units`/`subtotal` son SOLO de las líneas de este producto.
     *
     * @param  list<string>                                                                                                   $skus
     * @return list<array{customer: string, status: string, date: string, units: int, subtotal_cents: int, currency: string}>
     */
    private function orders(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($skus), '?'));

            $rows = DB::select(
                "select o.id,
                        o.ship_to->>'customer_name' as customer,
                        o.status,
                        o.created_at,
                        o.currency,
                        coalesce(sum((l->>'quantity')::int), 0) as units,
                        coalesce(sum((l->>'quantity')::int * (l->>'unit_price_cents')::int), 0) as subtotal
                   from commerce_orders o
                   cross join lateral jsonb_array_elements(o.lines) as l
                  where upper(l->>'sku') in ({$placeholders})
                  group by o.id, customer, o.status, o.created_at, o.currency
                  order by o.created_at desc
                  limit 12",
                $skus,
            );

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'customer' => $this->str($r->customer ?? null) ?? 'Cliente',
                    'status' => (string) $r->status,
                    'date' => $this->date($r->created_at ?? null),
                    'units' => (int) $r->units,
                    'subtotal_cents' => (int) $r->subtotal,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Nombre legible de la categoría (commerce/catalog), o null. Memoizado implícito por el memo del overview. */
    private function categoryName(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        try {
            $name = DB::table('commerce_categories')->where('id', $categoryId)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Razón social del vendedor (marketplace), o null. Cross-context, solo lectura. */
    private function sellerName(?string $sellerId): ?string
    {
        if ($sellerId === null) {
            return null;
        }

        try {
            $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Moneda a mostrar: la primera que aparezca en precios u órdenes; por defecto BOB.
     *
     * @param list<array{sku: string, channel: string, list_name: string, amount_cents: int, currency: string, status: string}> $prices
     * @param list<array{customer: string, status: string, date: string, units: int, subtotal_cents: int, currency: string}>    $orders
     */
    private function firstCurrency(array $prices, array $orders): string
    {
        foreach ($prices as $p) {
            if ($p['currency'] !== '') {
                return $p['currency'];
            }
        }
        foreach ($orders as $o) {
            if ($o['currency'] !== '') {
                return $o['currency'];
            }
        }

        return 'BOB';
    }

    /** Fecha legible "05 nov 2026" a partir de un timestamp. */
    private function date(mixed $value): string
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->isoFormat('DD MMM YYYY') : '—';
        } catch (Throwable) {
            return '—';
        }
    }

    /** Normaliza a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
