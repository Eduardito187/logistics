<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\CrossContext;

use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Domain\Contract\SellerDispatchReader;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;
use Throwable;

/**
 * Lee los despachos de un seller en un periodo desde la tabla `shipments` del contexto `shipments`, en
 * modo SOLO LECTURA y EN LOTE (patrón del skill para analítica cross-context: `DB::table(...)` envuelto en
 * try/catch, sin dependencia composer entre contextos).
 *
 * Un despacho es un envío con `owner_party_id = sellerId` en estado despachado/entregado (IN_EXECUTION o
 * DELIVERED) cuya última actualización cae dentro del periodo. El monto base de cada línea es el valor del
 * envío (suma de cantidad × precio unitario de sus líneas); si no hay precio, 0. Si la tabla no existe
 * (contexto extraído a microservicio), degrada a lista vacía.
 */
final class EloquentSellerDispatchReader implements SellerDispatchReader
{
    /**
     * Estados que cuentan como "despachado/entregado" para liquidar (valores de ShipmentStatus).
     *
     * @var list<string>
     */
    private const DISPATCHED_STATUSES = ['IN_EXECUTION', 'DELIVERED'];

    public function dispatchesForSeller(string $sellerId, SettlementPeriod $period, string $currency): array
    {
        try {
            $rows = DB::table('shipments')
                ->leftJoin('shipment_lines', 'shipment_lines.shipment_id', '=', 'shipments.id')
                ->where('shipments.owner_party_id', $sellerId)
                ->whereIn('shipments.status', self::DISPATCHED_STATUSES)
                ->whereBetween('shipments.updated_at', [
                    $period->fromString().' 00:00:00',
                    $period->toStringEnd().' 23:59:59',
                ])
                ->groupBy('shipments.id', 'shipments.external_id')
                ->orderBy('shipments.external_id')
                ->selectRaw('shipments.id as id, shipments.external_id as external_id, COALESCE(SUM(shipment_lines.quantity * shipment_lines.unit_price), 0) as base')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            /** @var object{id: string, external_id: string, base: int|float|string} $row */
            $baseCents = (int) round(((float) $row->base) * 100);
            $lines[] = SettlementLine::of(
                (string) $row->id,
                (string) $row->external_id,
                Money::of($baseCents, $currency),
            );
        }

        return $lines;
    }
}
