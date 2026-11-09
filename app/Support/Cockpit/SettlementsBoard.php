<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource;
use Throwable;

/**
 * Compositor de LECTURA del cockpit de LIQUIDACIONES: junta en una sola vista financiera las dos fuentes
 * que antes vivían como recursos sueltos —liquidaciones de TRANSPORTISTAS (`carrier_settlements`) y de
 * VENDEDORES del marketplace (`seller_settlements`)— con sus KPIs por estado, el total «por pagar» de cada
 * frente y una lista reciente enlazada a la ficha de cada liquidación.
 *
 * SOLO lectura y tolerante: cada frente en su try/catch; si una tabla/contexto no está disponible, su
 * sección degrada a 0/vacío sin romper la página. Agregación en SQL (1 GROUP-less `selectRaw` con
 * `count/sum filter` por estado) en vez de N consultas. Sin UUID visible: el transportista se resuelve a
 * su `name` y el vendedor a su `legal_name`; hacia afuera solo salen nombres, montos y periodos.
 */
final class SettlementsBoard
{
    /** Estados del ciclo de liquidación de transportista (mismos valores que el dominio). */
    private const array CARRIER_STATUSES = ['submitted', 'approved', 'paid', 'disputed'];

    /** Estados del ciclo de liquidación de vendedor (mismos valores que el dominio). */
    private const array SELLER_STATUSES = ['draft', 'confirmed', 'paid'];

    /**
     * Todo lo que el cockpit de liquidaciones necesita, en primitivos y tolerante.
     *
     * @return array{carrier: array<string, mixed>, seller: array<string, mixed>}
     */
    public function overview(): array
    {
        return [
            'carrier' => $this->carrier(),
            'seller' => $this->seller(),
        ];
    }

    /**
     * Frente TRANSPORTISTAS: KPIs por estado (sobre `amount_cents`), total por pagar (= approved),
     * alerta de objetadas (disputed) y lista reciente con el nombre real del transportista.
     *
     * @return array{counts: array<string, int>, amounts: array<string, int>, payable_cents: int, payable_label: string, disputed_count: int, currency: string, recent: list<array<string, mixed>>}
     */
    private function carrier(): array
    {
        $table = (new SettlementRecord)->getTable();

        $agg = $this->aggregate($table, 'amount_cents', self::CARRIER_STATUSES);
        $currency = $this->currencyOf($table);

        $recent = [];
        try {
            $rows = DB::table($table.' as s')
                ->leftJoin('carriers as c', 'c.id', '=', 's.carrier_id')
                ->orderByDesc('s.period')
                ->orderByDesc('s.reference')
                ->limit(15)
                ->get(['s.id', 's.status', 's.amount_cents', 's.currency', 's.period', 's.reference', 'c.name as carrier_name']);

            foreach ($rows as $r) {
                $recent[] = [
                    'name' => $this->str($r->carrier_name) ?? '—',
                    'subtitle' => trim(($this->str($r->period) ?? '').' · '.($this->str($r->reference) ?? '')),
                    'amount' => $this->money((int) $r->amount_cents, (string) $r->currency),
                    'status' => (string) $r->status,
                    'tone' => $this->carrierTone((string) $r->status),
                    'url' => $this->viewUrl(SettlementResource::class, (string) $r->id),
                ];
            }
        } catch (Throwable) {
            $recent = [];
        }

        return [
            'counts' => $agg['counts'],
            'amounts' => $agg['amounts'],
            'payable_cents' => $agg['amounts']['approved'] ?? 0,
            'payable_label' => $this->money($agg['amounts']['approved'] ?? 0, $currency),
            'disputed_count' => $agg['counts']['disputed'] ?? 0,
            'currency' => $currency,
            'recent' => $recent,
        ];
    }

    /**
     * Frente VENDEDORES: KPIs por estado (sobre `net_cents`, el neto por pagar), total por pagar
     * (= confirmed) y lista reciente con la razón social real del vendedor.
     *
     * @return array{counts: array<string, int>, amounts: array<string, int>, payable_cents: int, payable_label: string, currency: string, recent: list<array<string, mixed>>}
     */
    private function seller(): array
    {
        $table = (new SellerSettlementRecord)->getTable();

        $agg = $this->aggregate($table, 'net_cents', self::SELLER_STATUSES);
        $currency = $this->currencyOf($table);

        $recent = [];
        try {
            $rows = DB::table($table.' as s')
                ->leftJoin('sellers as sel', 'sel.id', '=', 's.seller_id')
                ->orderByDesc('s.period_to')
                ->limit(15)
                ->get(['s.id', 's.status', 's.net_cents', 's.gross_cents', 's.currency', 's.period_from', 's.period_to', 'sel.legal_name as seller_name']);

            foreach ($rows as $r) {
                $recent[] = [
                    'name' => $this->str($r->seller_name) ?? '—',
                    'subtitle' => trim(($this->str($r->period_from) ?? '').' — '.($this->str($r->period_to) ?? '')),
                    'amount' => $this->money((int) $r->net_cents, (string) $r->currency),
                    'status' => (string) $r->status,
                    'tone' => $this->sellerTone((string) $r->status),
                    'url' => $this->viewUrl(SellerSettlementResource::class, (string) $r->id),
                ];
            }
        } catch (Throwable) {
            $recent = [];
        }

        return [
            'counts' => $agg['counts'],
            'amounts' => $agg['amounts'],
            'payable_cents' => $agg['amounts']['confirmed'] ?? 0,
            'payable_label' => $this->money($agg['amounts']['confirmed'] ?? 0, $currency),
            'currency' => $currency,
            'recent' => $recent,
        ];
    }

    /**
     * Agrega conteo y suma de un monto por estado en una sola consulta (`count/sum filter`), tolerante.
     * Devuelve siempre todas las claves de estado (0 si no hay filas).
     *
     * @param  literal-string                                                 $amountColumn columna de monto (whitelist literal del código, no entrada de usuario)
     * @param  list<literal-string>                                           $statuses     estados del dominio (whitelist literal del código, no entrada de usuario)
     * @return array{counts: array<string, int>, amounts: array<string, int>}
     */
    private function aggregate(string $table, string $amountColumn, array $statuses): array
    {
        $counts = array_fill_keys($statuses, 0);
        $counts['total'] = 0;
        $amounts = array_fill_keys($statuses, 0);
        $amounts['total'] = 0;

        try {
            // SQL construido solo con literales del código (columna y estados son whitelist constante); por
            // eso se arma por concatenación `.=` —que preserva literal-string para selectRaw— y no con implode.
            $select = 'count(*) as c_total, coalesce(sum("'.$amountColumn.'"),0) as a_total';
            foreach ($statuses as $st) {
                $select .= ", count(*) filter (where status = '".$st."') as c_".$st;
                $select .= ', coalesce(sum("'.$amountColumn.'") filter (where status = \''.$st.'\'),0) as a_'.$st;
            }

            $row = DB::table($table)->selectRaw($select)->first();

            if ($row !== null) {
                $counts['total'] = (int) $row->c_total;
                $amounts['total'] = (int) $row->a_total;
                foreach ($statuses as $st) {
                    $counts[$st] = (int) ($row->{'c_'.$st} ?? 0);
                    $amounts[$st] = (int) ($row->{'a_'.$st} ?? 0);
                }
            }
        } catch (Throwable) {
            // degrada a ceros ya inicializados.
        }

        return ['counts' => $counts, 'amounts' => $amounts];
    }

    /**
     * Moneda representativa de un frente (la más frecuente); por defecto 'BOB' si no hay filas.
     */
    private function currencyOf(string $table): string
    {
        try {
            $value = DB::table($table)
                ->selectRaw('currency, count(*) as c')
                ->groupBy('currency')
                ->orderByDesc('c')
                ->value('currency');

            return is_string($value) && $value !== '' ? $value : 'BOB';
        } catch (Throwable) {
            return 'BOB';
        }
    }

    /** Color semántico del estado de una liquidación de transportista. */
    private function carrierTone(string $status): string
    {
        return match ($status) {
            'submitted' => 'warning',
            'approved' => 'agent',
            'paid' => 'positive',
            'disputed' => 'danger',
            default => '',
        };
    }

    /** Color semántico del estado de una liquidación de vendedor. */
    private function sellerTone(string $status): string
    {
        return match ($status) {
            'draft' => 'warning',
            'confirmed' => 'agent',
            'paid' => 'positive',
            default => '',
        };
    }

    /** Formatea centavos a «1,234.56 BOB». */
    private function money(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2).' '.($currency !== '' ? $currency : 'BOB');
    }

    /**
     * URL tolerante a la ficha de una liquidación (view → index si no hay view).
     *
     * @param class-string<\Filament\Resources\Resource> $resource
     */
    private function viewUrl(string $resource, string $id): ?string
    {
        try {
            return $resource::getUrl('view', ['record' => $id]);
        } catch (Throwable) {
            try {
                return $resource::getUrl('index');
            } catch (Throwable) {
                return null;
            }
        }
    }

    /** Normaliza a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
