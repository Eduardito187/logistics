<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Home del PREPARADOR (Fase 4 — homes por rol): lo que un preparador necesita al entrar al panel, su cola
 * de preparación. Agrega los envíos aún no ejecutados (recibidos/planificados) que hay que preparar, con
 * foco en los de hoy. SOLO lectura y tolerante; sin UUID visible (referencia = nº de pedido del envío).
 */
final class PickerHome
{
    /** Estados previos a la ejecución: el envío está en la cola de preparación. */
    private const array PRE_EXECUTION = ['RECEIVED', 'PLANNED'];

    /**
     * Resumen de la cola de preparación.
     *
     * @return array{to_prepare: int, today: int, received: int, planned: int, queue: list<array{reference: string, status: string, delivery_date: ?string, id: string}>}
     */
    public function overview(): array
    {
        $out = ['to_prepare' => 0, 'today' => 0, 'received' => 0, 'planned' => 0, 'queue' => []];

        try {
            $today = Carbon::today()->toDateString();

            $out['to_prepare'] = DB::table('shipments')->whereIn('status', self::PRE_EXECUTION)->count();
            $out['received'] = DB::table('shipments')->where('status', 'RECEIVED')->count();
            $out['planned'] = DB::table('shipments')->where('status', 'PLANNED')->count();
            $out['today'] = DB::table('shipments')
                ->whereIn('status', self::PRE_EXECUTION)
                ->whereDate('delivery_date', $today)
                ->count();

            $out['queue'] = array_values(DB::table('shipments')
                ->whereIn('status', self::PRE_EXECUTION)
                ->orderByRaw('delivery_date asc nulls last')
                ->limit(12)
                ->get(['id', 'external_id', 'status', 'delivery_date'])
                ->map(static fn ($r): array => [
                    'id' => (string) $r->id,
                    'reference' => (string) $r->external_id,
                    'status' => (string) $r->status,
                    'delivery_date' => is_string($r->delivery_date) && $r->delivery_date !== ''
                        ? Carbon::parse($r->delivery_date)->isoFormat('DD MMM')
                        : null,
                ])
                ->all());
        } catch (Throwable) {
            // sin contexto de envíos: cola vacía.
        }

        return $out;
    }
}
