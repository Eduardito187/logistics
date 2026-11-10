<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Home del TRANSPORTISTA (Fase 4 — homes por rol): la vista de liquidaciones de transporte al entrar al
 * panel. Agrega las liquidaciones pendientes (y su monto) y las más recientes, con el nombre del
 * transportista resuelto (nunca el UUID). SOLO lectura y tolerante.
 */
final class CarrierHome
{
    /** Estados de liquidación que NO cuentan como pendientes (ya cerradas). */
    private const array SETTLED = ['paid', 'settled', 'cancelled'];

    /**
     * Resumen de liquidaciones de transporte.
     *
     * @return array{carriers: int, open: int, open_amount_cents: int, currency: string, recent: list<array{carrier: string, period: string, amount_cents: int, currency: string, status: string}>}
     */
    public function overview(): array
    {
        $out = ['carriers' => 0, 'open' => 0, 'open_amount_cents' => 0, 'currency' => 'BOB', 'recent' => []];

        try {
            $out['carriers'] = DB::table('carriers')->count();

            $open = DB::table('carrier_settlements')->whereNotIn('status', self::SETTLED);
            $out['open'] = (clone $open)->count();
            $out['open_amount_cents'] = (int) (clone $open)->sum('amount_cents');

            $currency = DB::table('carrier_settlements')->value('currency');
            if (is_string($currency) && $currency !== '') {
                $out['currency'] = $currency;
            }

            $names = $this->carrierNames();
            $out['recent'] = array_values(DB::table('carrier_settlements')
                ->orderByDesc('created_at')
                ->limit(12)
                ->get(['carrier_id', 'period', 'amount_cents', 'currency', 'status'])
                ->map(static fn ($r): array => [
                    'carrier' => $names[(string) $r->carrier_id] ?? 'Transportista',
                    'period' => (string) $r->period,
                    'amount_cents' => (int) $r->amount_cents,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                    'status' => (string) $r->status,
                ])
                ->all());
        } catch (Throwable) {
            // sin contexto de transportistas: resumen vacío.
        }

        return $out;
    }

    /**
     * Nombres legibles de transportistas por id (para no mostrar UUIDs).
     *
     * @return array<string, string>
     */
    private function carrierNames(): array
    {
        try {
            /** @var array<string, string> $names */
            $names = DB::table('carriers')->pluck('name', 'id')->all();

            return $names;
        } catch (Throwable) {
            return [];
        }
    }
}
