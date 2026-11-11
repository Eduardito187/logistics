<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Execution\Presentation\Filament\Support\UserNames;
use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * CICLO DE VIDA de una etiqueta para el admin (control total con ACTOR): unifica, en una sola línea de
 * tiempo, quién GENERÓ / IMPRIMIÓ / REVOCÓ la etiqueta (bitácora `label_events`) y cada ESCANEO en terreno
 * (etapa + usuario + hora, de `execution_scan_events`, unidos por el bulto). Lectura tolerante: si una
 * fuente falla, se omite sin romper. Nada de UUID visible: usuario resuelto a nombre, etiqueta por su código.
 */
final class LabelLifecycle
{
    /**
     * Registra un evento de ciclo de vida (generación/impresión/revocación) con su actor. Idempotencia a
     * cargo de quien llama; append-only. Tolerante: nunca rompe el flujo de negocio por un fallo de bitácora.
     *
     * @param array<string, mixed>|null $meta
     */
    public function record(string $labelId, string $event, string $actor, string $actorName, ?string $orderId = null, ?array $meta = null): void
    {
        try {
            $now = (new DateTimeImmutable)->format(DATE_ATOM);
            DB::table('label_events')->insert([
                'id' => (string) Str::uuid7(),
                'label_id' => $labelId,
                'order_id' => $orderId,
                'event' => $event,
                'actor' => $actor,
                'actor_name' => $actorName !== '' ? $actorName : $actor,
                'meta' => $meta !== null ? json_encode($meta) : null,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        } catch (Throwable) {
            // bitácora no disponible: el ciclo de vida se degrada, no se interrumpe la acción.
        }
    }

    /**
     * Línea de tiempo del ciclo de vida de una etiqueta, ordenada cronológicamente (más reciente primero).
     *
     * @return list<array{at: string, when: string, event: string, label: string, actor: string, detail: string, tone: string}>
     */
    public function timeline(string $labelId): array
    {
        $unit = $this->resolveUnit($labelId);
        $truck = $this->truckAssignment($unit?->shipmentId);
        $scans = $this->scanEvents($unit?->unitId, $truck);

        $items = [...$this->baseEvents($labelId), ...$this->auditEvents($labelId), ...$scans];

        // Asignar el envío a un camión ES cargarlo (regla de operación): si no hubo escaneo de carga, la
        // asignación aparece como «Cargada» con la placa; si lo hubo, la placa ya va en ese escaneo.
        if ($truck !== null && !$this->hasLoadScan($scans)) {
            $items[] = [
                'at' => $truck['at'],
                'when' => $this->human($truck['at']),
                'event' => 'Cargada',
                'label' => 'camión',
                'actor' => '—',
                'detail' => 'Asignada al camión '.$truck['plate'],
                'tone' => 'info',
            ];
        }

        // Orden por INSTANTE, no por texto: las fuentes mezclan timestamptz (con offset, que depende de la zona
        // de la sesión de PostgreSQL) y timestamp sin zona (hora de la app); comparar strings las desordena.
        usort($items, fn (array $a, array $b): int => $this->instant($b['at']) <=> $this->instant($a['at']));

        return $items;
    }

    /**
     * Eventos SIEMPRE presentes de la propia etiqueta: GENERADA (emisión) y, si aplica, REVOCADA, leídos de
     * `labels_emitted` — así el ciclo de vida está completo aunque no haya bitácora de actor para esos dos.
     *
     * @return list<array{at: string, when: string, event: string, label: string, actor: string, detail: string, tone: string}>
     */
    private function baseEvents(string $labelId): array
    {
        try {
            $row = DB::table('labels_emitted')->where('label_id', $labelId)->first(['created_at', 'revoked_at', 'revoked_reason']);
        } catch (Throwable) {
            return [];
        }
        if ($row === null) {
            return [];
        }

        $out = [];
        // La emisión puede llevar actor si se registró en la bitácora; si no, queda sin actor (automática).
        $genActor = $this->actorFor($labelId, 'generated');
        $out[] = [
            'at' => (string) ($row->created_at ?? ''),
            'when' => $this->human((string) ($row->created_at ?? '')),
            'event' => 'Generada',
            'label' => 'etiqueta',
            'actor' => $genActor ?? '—',
            'detail' => '',
            'tone' => 'ok',
        ];
        if (is_string($row->revoked_at ?? null) && $row->revoked_at !== '') {
            $revActor = $this->actorFor($labelId, 'revoked');
            $out[] = [
                'at' => (string) $row->revoked_at,
                'when' => $this->human((string) $row->revoked_at),
                'event' => 'Revocada',
                'label' => 'etiqueta',
                'actor' => $revActor ?? '—',
                'detail' => is_string($row->revoked_reason ?? null) ? (string) $row->revoked_reason : '',
                'tone' => 'danger',
            ];
        }

        return $out;
    }

    /** Nombre del actor registrado para un evento puntual (generated/revoked) en la bitácora, o null. */
    private function actorFor(string $labelId, string $event): ?string
    {
        try {
            $name = DB::table('label_events')->where('label_id', $labelId)->where('event', $event)->orderByDesc('occurred_at')->value('actor_name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Eventos de la bitácora de etiqueta (generación/impresión/revocación) con actor.
     *
     * @return list<array{at: string, when: string, event: string, label: string, actor: string, detail: string, tone: string}>
     */
    private function auditEvents(string $labelId): array
    {
        try {
            $rows = DB::table('label_events')->where('label_id', $labelId)->orderBy('occurred_at')->get();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $at = (string) ($r->occurred_at ?? '');
            $meta = is_string($r->meta ?? null) ? (array) json_decode((string) $r->meta, true) : [];
            $out[] = [
                'at' => $at,
                'when' => $this->human($at),
                'event' => $this->eventLabel((string) $r->event),
                'label' => 'etiqueta',
                'actor' => is_string($r->actor_name ?? null) && $r->actor_name !== '' ? $r->actor_name : '—',
                'detail' => $this->auditDetail((string) $r->event, $meta),
                'tone' => $this->eventTone((string) $r->event),
            ];
        }

        return $out;
    }

    /**
     * Escaneos del bulto de la etiqueta (preparación, carga, prueba de entrega, recepción…) con su actor. El
     * escaneo de CARGA lleva la placa del camión al que está asignado el envío.
     *
     * @param  array{at: string, plate: string}|null                                                                            $truck
     * @return list<array{at: string, when: string, event: string, label: string, actor: string, detail: string, tone: string}>
     */
    private function scanEvents(?string $unitId, ?array $truck): array
    {
        if ($unitId === null) {
            return [];
        }

        try {
            $rows = DB::table('execution_scan_events')->where('unit_id', $unitId)->orderBy('occurred_at')->get();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $at = (string) ($r->occurred_at ?? '');
            $isLoad = strtolower((string) $r->type) === 'load';
            $out[] = [
                'at' => $at,
                'when' => $this->human($at),
                'event' => $this->scanLabel((string) $r->type),
                'label' => $isLoad ? 'carga' : 'escaneo',
                'actor' => UserNames::label(is_string($r->user_id ?? null) ? $r->user_id : null),
                'detail' => $isLoad && $truck !== null ? 'Camión '.$truck['plate'] : '',
                'tone' => 'info',
            ];
        }

        return $out;
    }

    /** Resuelve el bulto físico (y su envío) de la etiqueta para unir escaneos y camión. Tolerante: null. */
    private function resolveUnit(string $labelId): ?LabelUnitView
    {
        try {
            /** @var LabelUnitView $view */
            $view = app(QueryBus::class)->ask(new ResolveLabelUnit($labelId));

            return $view;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Camión al que está asignado el envío (parada de un plan de planificación) con su placa y cuándo se asignó.
     * Si se reasignó, manda la asignación más reciente. Tolerante: null si no hay o si la lectura falla.
     *
     * @return array{at: string, plate: string}|null
     */
    private function truckAssignment(?string $shipmentId): ?array
    {
        try {
            $row = $shipmentId === null ? null : DB::table('planning_plan_stops as ps')
                ->join('planning_plans as p', 'p.id', '=', 'ps.plan_id')
                ->leftJoin('fleet_vehicles as v', 'v.id', '=', 'p.vehicle_id')
                ->where('ps.shipment_id', $shipmentId)
                ->orderByDesc('ps.created_at')
                ->first(['ps.created_at', 'v.plate']);
        } catch (Throwable) {
            $row = null;
        }

        if ($row === null) {
            return null;
        }

        return [
            'at' => (string) ($row->created_at ?? ''),
            'plate' => is_string($row->plate ?? null) && $row->plate !== '' ? $row->plate : 'sin placa',
        ];
    }

    /** @param list<array{label: string}> $scans */
    private function hasLoadScan(array $scans): bool
    {
        foreach ($scans as $scan) {
            if ($scan['label'] === 'carga') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $meta */
    private function auditDetail(string $event, array $meta): string
    {
        if ($event === 'revoked' && isset($meta['reason']) && is_string($meta['reason'])) {
            return $meta['reason'];
        }
        if ($event === 'printed' && isset($meta['printer']) && is_string($meta['printer'])) {
            return $meta['printer'] === 'sticker' ? 'Impresora de pegatinas' : 'Impresora normal';
        }

        return '';
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'generated' => 'Generada',
            'printed' => 'Impresa',
            'revoked' => 'Revocada',
            default => ucfirst($event),
        };
    }

    /** Tono visual del evento de bitácora (revocación en rojo, impresión en azul, el resto neutro). */
    private function eventTone(string $event): string
    {
        return match ($event) {
            'revoked' => 'danger',
            'printed' => 'info',
            default => 'ok',
        };
    }

    private function scanLabel(string $type): string
    {
        // El tipo se guarda en MAYÚSCULA en execution_scan_events; se normaliza para mapearlo.
        return match (strtolower($type)) {
            'preparation' => 'Preparada',
            'load' => 'Cargada',
            'unload' => 'Descargada',
            'proof_of_delivery' => 'Entregada',
            'node_reception' => 'Recibida en nodo',
            'in_transit' => 'En tránsito',
            default => 'Escaneada ('.$type.')',
        };
    }

    private function human(string $iso): string
    {
        if ($iso === '') {
            return '—';
        }
        try {
            return $this->parse($iso)->format('d/m/Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    /** Segundos epoch del momento (0 si no se puede leer, queda al final). */
    private function instant(string $iso): int
    {
        try {
            return $iso === '' ? 0 : $this->parse($iso)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Interpreta el valor en la zona de la app (un timestamp sin zona ya está en hora de la app) y lo lleva a
     * esa zona, así un timestamptz con cualquier offset se muestra en hora local.
     */
    private function parse(string $iso): DateTimeImmutable
    {
        $zone = new DateTimeZone((string) config('app.timezone', 'UTC'));

        return (new DateTimeImmutable($iso, $zone))->setTimezone($zone);
    }
}
