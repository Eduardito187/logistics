<?php

declare(strict_types=1);

namespace Logistics\Settlement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementLineRecord;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Throwable;

/**
 * Seeder de demo del contexto Settlement: siembra UNA liquidación de ejemplo SOLO si ya existen sellers y
 * despachos de seller que liquidar; si no, es un no-op (no inventa datos huérfanos).
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): guarda por un id v7 fijo, de modo que un
 * segundo run no duplica ni regenera ids. Siembra directamente la proyección de lectura (records Eloquent):
 * es la vía estándar de demo y no depende del outbox. El integrador invoca este seeder desde el
 * DatabaseSeeder raíz.
 */
final class SettlementDemoSeeder extends Seeder
{
    /** Id v7 estable de la liquidación demo (no regenerar en re-run). */
    private const DEMO_SETTLEMENT_ID = '01931000-0000-7000-8000-00000000c001';

    private const DEMO_PERIOD_FROM = '2026-09-01';

    private const DEMO_PERIOD_TO = '2026-09-30';

    public function run(): void
    {
        // Idempotencia: si ya existe, no hacer nada.
        if (SellerSettlementRecord::query()->whereKey(self::DEMO_SETTLEMENT_ID)->exists()) {
            return;
        }

        $seller = $this->firstSeller();
        if ($seller === null) {
            return;
        }

        $dispatches = $this->dispatchesForSeller($seller['id']);
        if ($dispatches === []) {
            return;
        }

        // Comisión y fee fijo de los términos del seller (si no hay términos, valores por defecto).
        $commissionBp = $seller['commission_basis_points'];
        $fixedFeePer = $seller['fixed_fee_cents'];

        $gross = 0;
        foreach ($dispatches as $dispatch) {
            $gross += $dispatch['base_amount_cents'];
        }
        $commission = intdiv($gross * $commissionBp, 10000);
        $fixedFeeTotal = $fixedFeePer * count($dispatches);
        $net = max($gross - $commission - $fixedFeeTotal, 0);

        SellerSettlementRecord::query()->create([
            'id' => self::DEMO_SETTLEMENT_ID,
            'seller_id' => $seller['id'],
            'period_from' => self::DEMO_PERIOD_FROM,
            'period_to' => self::DEMO_PERIOD_TO,
            'gross_cents' => $gross,
            'currency' => 'BOB',
            'commission_basis_points' => $commissionBp,
            'commission_cents' => $commission,
            'fixed_fee_cents' => $fixedFeeTotal,
            'storage_fee_cents' => 0,
            'fulfillment_fee_cents' => 0,
            'last_mile_fee_cents' => 0,
            'net_cents' => $net,
            'status' => 'draft',
            'payment_reference' => null,
        ]);

        foreach ($dispatches as $dispatch) {
            SellerSettlementLineRecord::query()->create([
                'id' => Uuid::generate()->value,
                'settlement_id' => self::DEMO_SETTLEMENT_ID,
                'shipment_id' => $dispatch['shipment_id'],
                'dispatch_reference' => $dispatch['dispatch_reference'],
                'base_amount_cents' => $dispatch['base_amount_cents'],
                'currency' => 'BOB',
            ]);
        }
    }

    /**
     * Primer seller con sus términos de liquidación (lectura cross-context tolerante). Null si no hay.
     *
     * @return array{id: string, commission_basis_points: int, fixed_fee_cents: int}|null
     */
    private function firstSeller(): ?array
    {
        try {
            $row = DB::table('sellers')
                ->leftJoin('seller_settlement_terms', 'seller_settlement_terms.seller_id', '=', 'sellers.id')
                ->orderBy('sellers.id')
                ->selectRaw('sellers.id as id, COALESCE(seller_settlement_terms.commission_basis_points, 1500) as bp, COALESCE(seller_settlement_terms.fixed_fee_cents, 0) as fee')
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'commission_basis_points' => (int) $row->bp,
            'fixed_fee_cents' => (int) $row->fee,
        ];
    }

    /**
     * Despachos del seller (envíos despachados/entregados) como líneas de liquidación. [] si no hay.
     *
     * @return list<array{shipment_id: string, dispatch_reference: string, base_amount_cents: int}>
     */
    private function dispatchesForSeller(string $sellerId): array
    {
        try {
            $rows = DB::table('shipments')
                ->leftJoin('shipment_lines', 'shipment_lines.shipment_id', '=', 'shipments.id')
                ->where('shipments.owner_party_id', $sellerId)
                ->whereIn('shipments.status', ['IN_EXECUTION', 'DELIVERED'])
                ->groupBy('shipments.id', 'shipments.external_id')
                ->orderBy('shipments.external_id')
                ->selectRaw('shipments.id as id, shipments.external_id as external_id, COALESCE(SUM(shipment_lines.quantity * shipment_lines.unit_price), 0) as base')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'shipment_id' => (string) $row->id,
                'dispatch_reference' => (string) $row->external_id,
                'base_amount_cents' => (int) round(((float) $row->base) * 100),
            ];
        }

        return $lines;
    }
}
