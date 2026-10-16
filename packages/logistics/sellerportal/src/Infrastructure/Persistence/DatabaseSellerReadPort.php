<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Logistics\SellerPortal\Application\Contract\SellerReadPort;
use Logistics\SellerPortal\Application\ReadModel\SellerAsnView;
use Logistics\SellerPortal\Application\ReadModel\SellerSettlementView;
use Logistics\SellerPortal\Application\ReadModel\SellerStockView;
use Throwable;

/**
 * Lee los datos del seller directamente de las tablas read-only de `inbound` / `inventory` / `settlement`.
 *
 * NO depende por composer de esos contextos (patrón cross-context del skill): consulta sus tablas con
 * DB::table envuelto en try/catch, de modo que si una tabla aún no existe (contexto no desplegado) el
 * portal degrada a lista vacía en vez de romper. Toda consulta va SIEMPRE acotada por el seller: es la
 * garantía de que un seller nunca ve datos de otro. Se aplica un tope duro de filas por si acaso.
 */
final class DatabaseSellerReadPort implements SellerReadPort
{
    /** Tope de filas por consulta (lecturas de portal; la paginación real llegará si hace falta). */
    private const MAX_ROWS = 500;

    public function asns(string $sellerId): array
    {
        try {
            $rows = DB::table('inbound_asns')
                ->where('seller_id', $sellerId)
                ->orderByDesc('appointment_date')
                ->orderByDesc('id')
                ->limit(self::MAX_ROWS)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return array_values($rows->map(static fn (object $row): SellerAsnView => SellerAsnView::fromRow($row))->all());
    }

    public function stock(string $sellerId): array
    {
        try {
            $rows = DB::table('inventory_lots')
                ->where('owner_party_id', $sellerId)
                ->orderBy('sku')
                ->orderByDesc('received_at')
                ->limit(self::MAX_ROWS)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return array_values($rows->map(static fn (object $row): SellerStockView => SellerStockView::fromRow($row))->all());
    }

    public function settlements(string $sellerId): array
    {
        try {
            $rows = DB::table('seller_settlements')
                ->where('seller_id', $sellerId)
                ->orderByDesc('period_from')
                ->limit(self::MAX_ROWS)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return array_values($rows->map(static fn (object $row): SellerSettlementView => SellerSettlementView::fromRow($row))->all());
    }
}
