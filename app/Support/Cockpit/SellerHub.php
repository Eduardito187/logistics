<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un vendedor (seller del marketplace): agrega su ficha (razón
 * social, NIT, estado, contacto) y la actividad de LIQUIDACIONES (seller_settlements): pendientes/pagadas,
 * montos bruto/neto/comisión y las más recientes. Cross-context (sellers + settlement) por lectura directa,
 * patrón cockpit. SOLO lectura y tolerante; sin UUID visible (la referencia es la razón social).
 */
final class SellerHub
{
    /** Estados de liquidación que NO cuentan como pendientes (ya cerradas). */
    private const array SETTLED = ['paid', 'settled', 'cancelled'];

    /**
     * Todo lo que la ficha 360 del vendedor necesita, en primitivos y tolerante.
     *
     * @return array{
     *   seller: array{name: string, tax_id: ?string, status: string, contact_name: ?string, contact_email: ?string, contact_phone: ?string}|null,
     *   metrics: array{settlements: int, pending: int, paid: int, gross_cents: int, net_cents: int, commission_cents: int, currency: string},
     *   recent: list<array{period: string, gross_cents: int, net_cents: int, currency: string, status: string}>,
     * }
     */
    public function overview(string $sellerId): array
    {
        return [
            'seller' => $this->seller($sellerId),
            'metrics' => $this->metrics($sellerId),
            'recent' => $this->recent($sellerId),
        ];
    }

    /**
     * Ficha del vendedor, o null si no existe.
     *
     * @return array{name: string, tax_id: ?string, status: string, contact_name: ?string, contact_email: ?string, contact_phone: ?string}|null
     */
    private function seller(string $sellerId): ?array
    {
        try {
            $row = DB::table('sellers')->where('id', $sellerId)->first();
            if ($row === null) {
                return null;
            }

            return [
                'name' => (string) $row->legal_name,
                'tax_id' => $this->str($row->tax_id),
                'status' => (string) $row->status,
                'contact_name' => $this->str($row->contact_name),
                'contact_email' => $this->str($row->contact_email),
                'contact_phone' => $this->str($row->contact_phone),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Métricas de liquidaciones del vendedor (conteos + montos). Un solo GROUP BY con FILTER.
     *
     * @return array{settlements: int, pending: int, paid: int, gross_cents: int, net_cents: int, commission_cents: int, currency: string}
     */
    private function metrics(string $sellerId): array
    {
        $out = ['settlements' => 0, 'pending' => 0, 'paid' => 0, 'gross_cents' => 0, 'net_cents' => 0, 'commission_cents' => 0, 'currency' => 'BOB'];

        try {
            $settled = "'".implode("','", self::SETTLED)."'";
            $row = DB::table('seller_settlements')
                ->where('seller_id', $sellerId)
                ->selectRaw(
                    'count(*) as total, '
                    ."count(*) filter (where status not in ({$settled})) as pending, "
                    ."count(*) filter (where status = 'paid') as paid, "
                    .'coalesce(sum(gross_cents), 0) as gross, '
                    .'coalesce(sum(net_cents), 0) as net, '
                    .'coalesce(sum(commission_cents), 0) as commission, '
                    .'max(currency) as currency',
                )
                ->first();

            if ($row !== null) {
                $out['settlements'] = (int) $row->total;
                $out['pending'] = (int) $row->pending;
                $out['paid'] = (int) $row->paid;
                $out['gross_cents'] = (int) $row->gross;
                $out['net_cents'] = (int) $row->net;
                $out['commission_cents'] = (int) $row->commission;
                $out['currency'] = is_string($row->currency) && $row->currency !== '' ? $row->currency : 'BOB';
            }
        } catch (Throwable) {
            // sin contexto de liquidaciones.
        }

        return $out;
    }

    /**
     * Liquidaciones recientes del vendedor (período legible + montos + estado).
     *
     * @return list<array{period: string, gross_cents: int, net_cents: int, currency: string, status: string}>
     */
    private function recent(string $sellerId): array
    {
        try {
            return array_values(DB::table('seller_settlements')
                ->where('seller_id', $sellerId)
                ->orderByDesc('created_at')
                ->limit(12)
                ->get(['period_from', 'period_to', 'gross_cents', 'net_cents', 'currency', 'status'])
                ->map(fn ($r): array => [
                    'period' => $this->period($r->period_from, $r->period_to),
                    'gross_cents' => (int) $r->gross_cents,
                    'net_cents' => (int) $r->net_cents,
                    'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                    'status' => (string) $r->status,
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /** Período legible "01 oct – 15 oct 2026" a partir de las dos fechas. */
    private function period(mixed $from, mixed $to): string
    {
        try {
            $a = is_string($from) && $from !== '' ? Carbon::parse($from)->isoFormat('DD MMM') : '';
            $b = is_string($to) && $to !== '' ? Carbon::parse($to)->isoFormat('DD MMM YYYY') : '';

            return trim($a.' – '.$b, ' –');
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
