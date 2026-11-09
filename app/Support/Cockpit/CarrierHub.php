<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un transportista (carrier): agrega su ficha (nombre, estado,
 * calificación), sus liquidaciones (pendientes/pagadas + monto) y sus tarifas por corredor. Reemplaza el
 * CRUD plano por una ficha donde además viven, filtradas, sus tarifas y liquidaciones. SOLO lectura y
 * tolerante (cada fuente en su try/catch). Sin UUID visible: la referencia es el nombre del transportista.
 */
final class CarrierHub
{
    /** Estados de liquidación que NO cuentan como pendientes (ya cerradas). */
    private const array SETTLED = ['paid', 'settled', 'cancelled'];

    /**
     * Todo lo que la ficha del transportista necesita, en primitivos y tolerante.
     *
     * @return array{
     *   carrier: array{name: string, tax_id: ?string, status: string, rating: ?float, rating_count: int}|null,
     *   settlements: array{open: int, open_amount_cents: int, paid: int, total: int, currency: string, recent: list<array{period: string, amount_cents: int, currency: string, status: string, corridor: string}>},
     *   tariffs: array{total: int, recent: list<array{corridor: string, vehicle_class: ?string, price_cents: int, currency: string}>},
     * }
     */
    public function overview(string $carrierId): array
    {
        return [
            'carrier' => $this->carrier($carrierId),
            'settlements' => $this->settlements($carrierId),
            'tariffs' => $this->tariffs($carrierId),
        ];
    }

    /**
     * Ficha del transportista, o null si no existe.
     *
     * @return array{name: string, tax_id: ?string, status: string, rating: ?float, rating_count: int}|null
     */
    private function carrier(string $carrierId): ?array
    {
        try {
            $row = DB::table('carriers')->where('id', $carrierId)->first(['name', 'tax_id', 'status', 'rating', 'rating_count']);
            if ($row === null) {
                return null;
            }

            return [
                'name' => (string) $row->name,
                'tax_id' => $this->str($row->tax_id),
                'status' => (string) $row->status,
                'rating' => $row->rating !== null ? round((float) $row->rating, 2) : null,
                'rating_count' => (int) $row->rating_count,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Liquidaciones del transportista: pendientes/pagadas + monto pendiente + recientes.
     *
     * @return array{open: int, open_amount_cents: int, paid: int, total: int, currency: string, recent: list<array{period: string, amount_cents: int, currency: string, status: string, corridor: string}>}
     */
    private function settlements(string $carrierId): array
    {
        $out = ['open' => 0, 'open_amount_cents' => 0, 'paid' => 0, 'total' => 0, 'currency' => 'BOB', 'recent' => []];

        try {
            $base = DB::table('carrier_settlements')->where('carrier_id', $carrierId);

            $out['total'] = (clone $base)->count();
            $out['open'] = (clone $base)->whereNotIn('status', self::SETTLED)->count();
            $out['open_amount_cents'] = (int) (clone $base)->whereNotIn('status', self::SETTLED)->sum('amount_cents');
            $out['paid'] = (clone $base)->whereIn('status', ['paid', 'settled'])->count();

            $currency = (clone $base)->value('currency');
            if (is_string($currency) && $currency !== '') {
                $out['currency'] = $currency;
            }

            $out['recent'] = array_values((clone $base)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['period', 'amount_cents', 'currency', 'status', 'corridor_from', 'corridor_to', 'corridor_type'])
                ->map(fn ($r): array => [
                    'period' => (string) $r->period,
                    'amount_cents' => (int) $r->amount_cents,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                    'status' => (string) $r->status,
                    'corridor' => $this->corridor($r->corridor_from, $r->corridor_to, $r->corridor_type),
                ])
                ->all());
        } catch (Throwable) {
            // sin contexto de liquidaciones.
        }

        return $out;
    }

    /**
     * Tarifas del transportista por corredor.
     *
     * @return array{total: int, recent: list<array{corridor: string, vehicle_class: ?string, price_cents: int, currency: string}>}
     */
    private function tariffs(string $carrierId): array
    {
        $out = ['total' => 0, 'recent' => []];

        try {
            $out['total'] = DB::table('carrier_tariffs')->where('carrier_id', $carrierId)->count();
            $out['recent'] = array_values(DB::table('carrier_tariffs')
                ->where('carrier_id', $carrierId)
                ->orderByDesc('effective_from')
                ->limit(10)
                ->get(['corridor_from', 'corridor_to', 'corridor_type', 'vehicle_class', 'price_cents', 'currency'])
                ->map(fn ($r): array => [
                    'corridor' => $this->corridor($r->corridor_from, $r->corridor_to, $r->corridor_type),
                    'vehicle_class' => $this->str($r->vehicle_class),
                    'price_cents' => (int) $r->price_cents,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                ])
                ->all());
        } catch (Throwable) {
            // sin contexto de tarifas.
        }

        return $out;
    }

    /** Etiqueta legible de un corredor: "ORIGEN → DESTINO · tipo". */
    private function corridor(mixed $from, mixed $to, mixed $type): string
    {
        $f = $this->str($from) ?? '—';
        $t = $this->str($to) ?? '—';
        $ty = $this->str($type);
        $label = $f.' → '.$t;

        return $ty !== null ? $label.' · '.strtoupper(str_replace('_', ' ', $ty)) : $label;
    }

    /** Normaliza a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
