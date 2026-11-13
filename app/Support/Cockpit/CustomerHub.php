<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA del "Cliente 360": agrega, por nombre de cliente (la clave de agrupación que ya
 * usa la ficha de cliente, contrato compartido entre frentes), todo lo que ese cliente tiene en la
 * plataforma — sus envíos, sus pedidos comerciales (OMS), sus entregas y su satisfacción — más métricas
 * derivadas (gasto histórico, efectividad, última actividad) y sus direcciones.
 *
 * SOLO lectura y tolerante: cada fuente en su try/catch; si un contexto no está disponible, su sección
 * degrada a vacío/0 sin romper. Lee de forma directa (patrón cockpit) porque cruza varios contextos; no
 * toca dominio. Sin UUID visible hacia afuera: la referencia del cliente es su nombre y sus datos.
 */
final class CustomerHub
{
    /** Estados de envío terminales (para separar activos de cerrados). */
    private const array SHIPMENT_TERMINAL = ['DELIVERED', 'FAILED', 'CANCELLED'];

    /**
     * Todo lo que la ficha 360 del cliente necesita, en primitivos y tolerante (cacheado; ver {@see compose}).
     *
     * @return array<string, mixed>
     */
    public function overview(string $name): array
    {
        // Cacheado en forma PRIMITIVA (array, regla 7) por nombre de cliente, TTL corto: la ficha 360 se
        // recalculaba cross-context en cada render; 60 s absorbe ráfagas (recargas/polls) sin ensuciar la
        // frescura operativa. Tolerante: si la caché no está disponible, se computa igual.
        try {
            /** @var array<string, mixed> $result */
            // sha256 solo para derivar una clave de caché determinista (contexto NO sensible); no es un hash
            // de credenciales. Se usa sha256 (no md5) para no disparar reglas de hashing débil.
            $result = Cache::remember('cockpit:customer:'.hash('sha256', $name), 60, fn (): array => $this->compose($name));

            return $result;
        } catch (Throwable) {
            return $this->compose($name);
        }
    }

    /**
     * Compone el overview (sin caché). Separado para poder cachearlo.
     *
     * @return array<string, mixed>
     */
    private function compose(string $name): array
    {
        return [
            'identity' => $this->identity($name),
            'metrics' => $this->metrics($name),
            'shipments' => $this->shipments($name),
            'orders' => $this->orders($name),
            'satisfaction' => $this->satisfaction($name),
            'addresses' => $this->addresses($name),
        ];
    }

    /**
     * Datos de contacto, tomados del envío más reciente del cliente.
     *
     * @return array{name: string, document: ?string, phone: ?string, email: ?string}
     */
    private function identity(string $name): array
    {
        $out = ['name' => $name, 'document' => null, 'phone' => null, 'email' => null];

        try {
            $row = DB::table('shipments')
                ->where('customer_name', $name)
                ->orderByDesc('created_at')
                ->first(['customer_document_id', 'customer_phone', 'customer_email']);
            if ($row !== null) {
                $out['document'] = $this->str($row->customer_document_id);
                $out['phone'] = $this->str($row->customer_phone);
                $out['email'] = $this->str($row->customer_email);
            }
        } catch (Throwable) {
            // sin datos de contacto: queda solo el nombre.
        }

        return $out;
    }

    /**
     * Métricas derivadas del cliente (conteos, efectividad, gasto, satisfacción, última actividad).
     *
     * @return array{orders: int, shipments: int, active_shipments: int, delivered: int, failed: int, effectiveness: ?int, spend_cents: int, currency: string, satisfaction_avg: ?float, satisfaction_count: int, last_activity: ?string}
     */
    private function metrics(string $name): array
    {
        $shipments = 0;
        $active = 0;
        $delivered = 0;
        $failed = 0;
        $shipmentOrders = 0;
        $lastActivity = null;

        try {
            // Un solo GROUP BY con FILTER (antes eran 5 consultas separadas): usa el índice (customer_name,
            // created_at) y hace 1 round-trip.
            $terminal = "'".implode("','", self::SHIPMENT_TERMINAL)."'";
            $row = DB::table('shipments')
                ->where('customer_name', $name)
                ->selectRaw(
                    'count(*) as total, '
                    ."count(*) filter (where status not in ({$terminal})) as active, "
                    ."count(*) filter (where status = 'DELIVERED') as delivered, "
                    ."count(*) filter (where status = 'FAILED') as failed, "
                    ."count(distinct order_reference) filter (where order_reference is not null and order_reference <> '') as order_refs, "
                    .'max(created_at) as last_at',
                )
                ->first();

            if ($row !== null) {
                $shipments = (int) $row->total;
                $active = (int) $row->active;
                $delivered = (int) $row->delivered;
                $failed = (int) $row->failed;
                $shipmentOrders = (int) $row->order_refs;
                $lastActivity = is_string($row->last_at) ? $this->shortDate($row->last_at) : null;
            }
        } catch (Throwable) {
            // sin contexto de envíos.
        }

        $closed = $delivered + $failed;
        $effectiveness = $closed > 0 ? (int) round($delivered / $closed * 100) : null;

        [$spend, $currency, $ordersCount] = $this->spend($name);
        [$satAvg, $satCount] = $this->satisfactionStats($name);

        // "Pedidos" = cantidad de órdenes del cliente. Un envío SIEMPRE pertenece a una orden
        // (`order_reference`), pero puede no existir una orden comercial (OMS) si entró por SAP/sembrado.
        // Por eso se toma el mayor entre las órdenes comerciales y las órdenes distintas de sus envíos:
        // así nunca aparece "0 pedidos · 1 envío".
        $orders = max($ordersCount, $shipmentOrders);

        return [
            'orders' => $orders,
            'shipments' => $shipments,
            'active_shipments' => $active,
            'delivered' => $delivered,
            'failed' => $failed,
            'effectiveness' => $effectiveness,
            'spend_cents' => $spend,
            'currency' => $currency,
            'satisfaction_avg' => $satAvg,
            'satisfaction_count' => $satCount,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * Gasto histórico del cliente y conteo de pedidos: suma de (precio × cantidad) de las líneas de sus
     * pedidos comerciales (OMS), emparejados por el nombre del cliente en `ship_to`.
     *
     * @return array{0: int, 1: string, 2: int} [gasto_centavos, moneda, cantidad_de_pedidos]
     */
    private function spend(string $name): array
    {
        try {
            // Suma del gasto en SQL (antes se traían TODAS las órdenes y se sumaban las líneas en PHP). El
            // lateral desagrega el array `lines` jsonb y suma precio×cantidad por orden; el outer suma todo y
            // cuenta las órdenes. `jsonb_typeof` protege contra `lines` null/no-array. Usa el índice de
            // expresión sobre `ship_to->>'customer_name'`.
            $row = DB::selectOne(
                "select coalesce(sum(s.line_total), 0)::bigint as cents, count(*) as orders, max(o.currency) as currency
                 from commerce_orders o
                 left join lateral (
                     select coalesce(sum((l->>'unit_price_cents')::bigint * (l->>'quantity')::int), 0) as line_total
                     from jsonb_array_elements(
                         case when jsonb_typeof((o.lines)::jsonb) = 'array' then (o.lines)::jsonb else '[]'::jsonb end
                     ) as l
                 ) s on true
                 where o.ship_to->>'customer_name' = ?",
                [$name],
            );

            $data = $row === null ? [] : (array) $row;
            $currency = isset($data['currency']) && is_string($data['currency']) && $data['currency'] !== '' ? $data['currency'] : 'BOB';
            $cents = isset($data['cents']) ? (int) $data['cents'] : 0;
            $orders = isset($data['orders']) ? (int) $data['orders'] : 0;

            return [$cents, $currency, $orders];
        } catch (Throwable) {
            return [0, 'BOB', 0];
        }
    }

    /**
     * Promedio y conteo de calificaciones de satisfacción del cliente (encuestas de sus envíos).
     *
     * @return array{0: ?float, 1: int} [promedio, cantidad]
     */
    private function satisfactionStats(string $name): array
    {
        try {
            $ratings = DB::table('tracking_satisfaction_surveys as s')
                ->join('shipments as sh', 'sh.id', '=', 's.shipment_id')
                ->where('sh.customer_name', $name)
                ->whereNotNull('s.rating')
                ->pluck('s.rating');

            if ($ratings->isEmpty()) {
                return [null, 0];
            }

            $avg = round($ratings->map(static fn ($r): int => (int) $r)->avg() ?? 0, 1);

            return [$avg, $ratings->count()];
        } catch (Throwable) {
            return [null, 0];
        }
    }

    /**
     * Envíos del cliente (más recientes primero), con referencia legible (nº de pedido) para enlazar.
     *
     * @return list<array{id: string, reference: string, status: string, delivery_date: ?string}>
     */
    private function shipments(string $name): array
    {
        try {
            return array_values(DB::table('shipments')
                ->where('customer_name', $name)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'external_id', 'status', 'delivery_date'])
                ->map(fn ($r): array => [
                    'id' => (string) $r->id,
                    'reference' => (string) $r->external_id,
                    'status' => (string) $r->status,
                    'delivery_date' => $this->str($r->delivery_date),
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pedidos comerciales del cliente (por nombre en `ship_to`), con total e id para enlazar a su hub.
     *
     * @return list<array{id: string, status: string, total_cents: int, currency: string, created: ?string}>
     */
    private function orders(string $name): array
    {
        try {
            return array_values(DB::table('commerce_orders')
                ->whereRaw("ship_to->>'customer_name' = ?", [$name])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'status', 'lines', 'currency', 'created_at'])
                ->map(function ($r): array {
                    $cents = 0;
                    $lines = is_string($r->lines) ? json_decode($r->lines, true) : [];
                    if (is_array($lines)) {
                        foreach ($lines as $line) {
                            if (is_array($line)) {
                                $cents += (isset($line['unit_price_cents']) ? (int) $line['unit_price_cents'] : 0)
                                    * (isset($line['quantity']) ? (int) $line['quantity'] : 0);
                            }
                        }
                    }

                    return [
                        'id' => (string) $r->id,
                        'status' => (string) $r->status,
                        'total_cents' => $cents,
                        'currency' => is_string($r->currency) && $r->currency !== '' ? $r->currency : 'BOB',
                        'created' => $this->shortDate(is_string($r->created_at) ? $r->created_at : null),
                    ];
                })
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Calificaciones de satisfacción del cliente (de sus envíos), más recientes primero.
     *
     * @return list<array{rating: int, comment: ?string, when: ?string}>
     */
    private function satisfaction(string $name): array
    {
        try {
            return array_values(DB::table('tracking_satisfaction_surveys as s')
                ->join('shipments as sh', 'sh.id', '=', 's.shipment_id')
                ->where('sh.customer_name', $name)
                ->orderByDesc('s.created_at')
                ->limit(20)
                ->get(['s.rating', 's.comment', 's.created_at'])
                ->map(fn ($r): array => [
                    'rating' => (int) $r->rating,
                    'comment' => $this->str($r->comment),
                    'when' => $this->shortDate(is_string($r->created_at) ? $r->created_at : null),
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Direcciones de entrega únicas del cliente (texto + referencia + conteo de envíos a cada una).
     *
     * @return list<array{text: string, reference: ?string, count: int}>
     */
    private function addresses(string $name): array
    {
        try {
            $byText = [];
            foreach (DB::table('shipments')
                ->where('customer_name', $name)
                ->get(['address_text', 'address_reference']) as $row) {
                $text = (string) $row->address_text;
                if ($text === '') {
                    continue;
                }
                if (!isset($byText[$text])) {
                    $byText[$text] = ['text' => $text, 'reference' => $this->str($row->address_reference), 'count' => 0];
                }
                $byText[$text]['count']++;
            }

            return array_values($byText);
        } catch (Throwable) {
            return [];
        }
    }

    /** Normaliza un valor a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Fecha legible corta "19 oct 2026", tolerante. */
    private function shortDate(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM YYYY');
        } catch (Throwable) {
            return null;
        }
    }
}
