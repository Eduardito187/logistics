<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Calendario de despacho del admin: muestra, para un día elegido, los cupos de entrega por scope
 * (nodo o zona) y franja horaria, con total / usado / disponible, y el resumen de los horarios
 * configurados (días hábiles, corte, ventanas).
 *
 * Nota de arquitectura: los NÚMEROS salen del lado de lectura CQRS (QueryBus → ListBucketsByDateQuery
 * → CapacityView), nunca de un acceso directo al agregado. Los nombres legibles de nodo/zona se
 * resuelven desde las proyecciones de lectura (NodeRecord/ZoneRecord) solo para presentación. Todo
 * texto visible va en inglés (regla 5); comentarios en español (regla 6).
 */
final class DispatchCalendar extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 20;

    protected string $view = 'logistics-capacity::filament.pages.dispatch-calendar';

    /** Día que se está mirando, en 'Y-m-d'. Enlazado al selector de fecha de la vista. */
    public string $date = '';

    public function mount(): void
    {
        // Por defecto, el día de hoy.
        if ($this->date === '') {
            $this->date = date('Y-m-d');
        }
    }

    public static function getNavigationGroup(): string
    {
        return __('Capacity');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dispatch calendar');
    }

    public function getTitle(): string
    {
        return __('Dispatch calendar');
    }

    /**
     * Avanza o retrocede el día mirado (botones del encabezado del calendario).
     */
    public function shiftDay(int $days): void
    {
        $timestamp = strtotime($this->date." {$days} day");
        // strtotime devuelve false si la fecha actual no es parseable: en ese caso no se mueve el día.
        if ($timestamp === false) {
            return;
        }

        $this->date = date('Y-m-d', $timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $date = $this->normalizedDate();

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<CapacityView> $buckets */
        $buckets = $queryBus->ask(new ListBucketsByDateQuery($date));

        return [
            'date' => $date,
            'windows' => $this->configuredWindows(),
            'scopes' => $this->groupByScope($buckets),
            'schedule' => $this->scheduleSummary(),
            'unitLabels' => $this->unitLabels(),
        ];
    }

    /**
     * Agrupa los cupos del día por scope (nodo o zona), y dentro de cada scope por franja. Cada celda
     * lleva total/usado/disponible por unidad.
     *
     * @param  list<CapacityView>                                                                      $buckets
     * @return list<array{key: string, label: string, kind: string, rows: list<array<string, mixed>>}>
     */
    private function groupByScope(array $buckets): array
    {
        $nodeNames = $this->nodeNames($buckets);
        $zoneNames = $this->zoneNames($buckets);

        /** @var array<string, array{key: string, label: string, kind: string, rows: list<array<string, mixed>>}> $scopes */
        $scopes = [];
        foreach ($buckets as $bucket) {
            if ($bucket->nodeId !== null) {
                $key = 'node:'.$bucket->nodeId;
                $label = $nodeNames[$bucket->nodeId] ?? $bucket->nodeId;
                $kind = __('Node');
            } else {
                $key = 'zone:'.((string) $bucket->zoneId);
                $label = $zoneNames[(string) $bucket->zoneId] ?? (string) $bucket->zoneId;
                $kind = __('Zone');
            }

            $scopes[$key] ??= ['key' => $key, 'label' => $label, 'kind' => $kind, 'rows' => []];
            $scopes[$key]['rows'][] = [
                'time_slot' => $bucket->timeSlot,
                'unit' => $bucket->unit,
                'total' => $bucket->total,
                'used' => $bucket->used,
                'available' => $bucket->available,
                'full' => $bucket->full,
            ];
        }

        // Orden estable: por etiqueta de scope, y dentro por franja y unidad.
        $ordered = array_values($scopes);
        usort($ordered, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        foreach ($ordered as &$scope) {
            usort($scope['rows'], static function (array $a, array $b): int {
                return [$a['time_slot'], $a['unit']] <=> [$b['time_slot'], $b['unit']];
            });
        }
        unset($scope);

        return $ordered;
    }

    /**
     * Nombres de nodo (code — name) para los ids presentes en los cupos.
     *
     * @param  list<CapacityView>    $buckets
     * @return array<string, string>
     */
    private function nodeNames(array $buckets): array
    {
        $ids = array_values(array_filter(array_map(static fn (CapacityView $b): ?string => $b->nodeId, $buckets)));
        if ($ids === []) {
            return [];
        }

        return NodeRecord::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(static fn (NodeRecord $n): array => [$n->id => "{$n->code} — {$n->name}"])
            ->all();
    }

    /**
     * Nombres de zona para los ids presentes en los cupos.
     *
     * @param  list<CapacityView>    $buckets
     * @return array<string, string>
     */
    private function zoneNames(array $buckets): array
    {
        $ids = array_values(array_filter(array_map(static fn (CapacityView $b): ?string => $b->zoneId, $buckets)));
        if ($ids === []) {
            return [];
        }

        return ZoneRecord::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (ZoneRecord $z): array => [$z->id => $z->name])
            ->all();
    }

    /**
     * Ventanas de franja configuradas (columnas del calendario).
     *
     * @return list<string>
     */
    private function configuredWindows(): array
    {
        /** @var Config $config */
        $config = app(Config::class);
        $windows = $config->get('logistics-capacity.dispatch_calendar.windows', []);

        return is_array($windows) ? array_values(array_map(strval(...), $windows)) : [];
    }

    /**
     * Resumen de los horarios configurados, para mostrarlo junto al calendario.
     *
     * @return array<string, mixed>
     */
    private function scheduleSummary(): array
    {
        /** @var Config $config */
        $config = app(Config::class);
        $base = 'logistics-capacity.dispatch_calendar.';

        $days = $config->get($base.'business_days', []);
        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];

        return [
            'business_days' => is_array($days)
                ? implode(', ', array_map(static fn ($d): string => (string) ($names[(int) $d] ?? $d), $days))
                : '',
            'order_cutoff' => (string) $config->get($base.'order_cutoff', ''),
            'horizon_days' => (int) $config->get($base.'horizon_days', 0),
            'default_slot_deliveries' => (int) $config->get($base.'default_slot_deliveries', 0),
        ];
    }

    /**
     * Etiquetas legibles de cada unidad de capacidad.
     *
     * @return array<string, string>
     */
    private function unitLabels(): array
    {
        $labels = [];
        foreach (CapacityUnit::cases() as $unit) {
            $labels[$unit->value] = $unit->label();
        }

        return $labels;
    }

    /**
     * Normaliza la fecha mirada a 'Y-m-d', cayendo a hoy si viene vacía o mal formada.
     */
    private function normalizedDate(): string
    {
        $time = strtotime($this->date);

        return $time === false ? date('Y-m-d') : date('Y-m-d', $time);
    }
}
