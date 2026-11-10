<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CockpitNote;
use BackedEnum;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Throwable;

/**
 * Agenda del equipo (Fase 5 CRM): junta TODAS las tareas abiertas (seguimientos) que la oficina cargó en
 * cualquier ficha 360 — cliente, pedido, envío, vehículo, chofer, nodo — y las ordena para trabajar el día:
 * vencidas primero, luego hoy, próximas y sin fecha. Cada tarea enlaza a la ficha de su entidad y se puede
 * marcar hecha desde acá. SOLO lectura + marcar hecha; sin UUID visible (resuelve cada sujeto a su nombre).
 *
 * Página cockpit (capa `.distrack-cockpit`, español directo), alcance de oficina, grupo Operaciones.
 */
final class Agenda extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-check-circle';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.agenda';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'Agenda';
    }

    public function getTitle(): string
    {
        return 'Agenda';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Tareas abiertas agrupadas por urgencia: vencidas, hoy, próximas, sin fecha. Resuelve en lote el
     * nombre legible y el enlace de cada entidad (nunca UUID).
     *
     * @return array{overdue: list<array<string,mixed>>, today: list<array<string,mixed>>, upcoming: list<array<string,mixed>>, nodate: list<array<string,mixed>>, total: int}
     */
    #[Computed]
    public function tasks(): array
    {
        $empty = ['overdue' => [], 'today' => [], 'upcoming' => [], 'nodate' => [], 'total' => 0];

        try {
            $rows = CockpitNote::query()
                ->where('kind', CockpitNote::KIND_TASK)
                ->whereNull('done_at')
                ->orderByRaw('due_on is null')
                ->orderBy('due_on')
                ->limit(300)
                ->get();

            if ($rows->isEmpty()) {
                return $empty;
            }

            // Resolución de etiquetas por tipo, en lote (sin UUID visible).
            $labels = $this->resolveLabels($rows);
            $today = Carbon::today();

            $groups = ['overdue' => [], 'today' => [], 'upcoming' => [], 'nodate' => []];
            foreach ($rows as $n) {
                $type = (string) $n->subject_type;
                $sid = (string) $n->subject_id;
                $item = [
                    'id' => (int) $n->getKey(),
                    'body' => (string) $n->body,
                    'author' => \is_string($n->author_label) && $n->author_label !== '' ? $n->author_label : 'Oficina',
                    'entity' => $this->typeLabel($type),
                    'subject' => $labels[$type][$sid] ?? $this->typeLabel($type),
                    'url' => $this->subjectUrl($type, $sid, $labels[$type][$sid] ?? ''),
                    'due' => $n->due_on?->isoFormat('DD MMM YYYY'),
                ];

                if ($n->due_on === null) {
                    $groups['nodate'][] = $item;
                } elseif ($n->due_on->lt($today)) {
                    $groups['overdue'][] = $item;
                } elseif ($n->due_on->isSameDay($today)) {
                    $groups['today'][] = $item;
                } else {
                    $groups['upcoming'][] = $item;
                }
            }

            return [...$groups, 'total' => $rows->count()];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** Marca una tarea como hecha (desaparece de la agenda). */
    public function completeTask(int $noteId): void
    {
        try {
            $note = CockpitNote::query()->whereKey($noteId)->where('kind', CockpitNote::KIND_TASK)->first();
            if ($note === null) {
                return;
            }
            $note->done_at = now();
            $note->save();
            unset($this->tasks);

            Notification::make()->title('Tarea completada.')->success()->send();
        } catch (Throwable) {
            // tolerante: no romper la agenda.
        }
    }

    /**
     * Resuelve, por tipo de entidad y en lote, el nombre legible de cada sujeto referenciado por las tareas.
     *
     * @param  iterable<CockpitNote>                $rows
     * @return array<string, array<string, string>> [type => [id => label]]
     */
    private function resolveLabels(iterable $rows): array
    {
        $byType = [];
        foreach ($rows as $n) {
            $byType[(string) $n->subject_type][] = (string) $n->subject_id;
        }

        $out = [];
        foreach ($byType as $type => $ids) {
            $ids = array_values(array_unique($ids));
            $out[$type] = match ($type) {
                'customer' => array_combine($ids, $ids), // el id ya es el nombre
                'vehicle' => $this->pluck('fleet_vehicles', 'plate', $ids),
                'driver' => $this->pluck('fleet_drivers', 'name', $ids),
                'node' => $this->pluck('network_nodes', 'name', $ids),
                'shipment' => $this->pluck('shipments', 'external_id', $ids),
                'order' => $this->orderLabels($ids),
                default => [],
            };
        }

        return $out;
    }

    /**
     * Mapa id → columna legible de una tabla, tolerante.
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function pluck(string $table, string $column, array $ids): array
    {
        try {
            /** @var array<string, string> $map */
            $map = DB::table($table)->whereIn('id', $ids)->pluck($column, 'id')->all();

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Etiquetas de pedidos: el nombre del cliente del `ship_to`.
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function orderLabels(array $ids): array
    {
        try {
            $out = [];
            foreach (DB::table('commerce_orders')->whereIn('id', $ids)->get(['id', 'ship_to']) as $row) {
                $shipTo = \is_string($row->ship_to) ? json_decode($row->ship_to, true) : [];
                $name = \is_array($shipTo) && isset($shipTo['customer_name']) && \is_string($shipTo['customer_name'])
                    ? $shipTo['customer_name']
                    : 'Pedido';
                $out[(string) $row->id] = $name;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Enlace a la ficha 360 del sujeto, tolerante (null si no se puede resolver). */
    private function subjectUrl(string $type, string $id, string $label): ?string
    {
        try {
            return match ($type) {
                'customer' => CustomerProfile::urlFor($label !== '' ? $label : $id),
                'vehicle' => VehicleResource::getUrl('view', ['record' => $id]),
                'driver' => DriverResource::getUrl('view', ['record' => $id]),
                'node' => NodeResource::getUrl('view', ['record' => $id]),
                'shipment' => ShipmentResource::getUrl('view', ['record' => $id]),
                'order' => OrderResource::getUrl('view', ['record' => $id]),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /** Nombre legible del tipo de entidad. */
    private function typeLabel(string $type): string
    {
        return match ($type) {
            'customer' => 'Cliente',
            'vehicle' => 'Vehículo',
            'driver' => 'Chofer',
            'node' => 'Nodo',
            'shipment' => 'Envío',
            'order' => 'Pedido',
            default => ucfirst($type),
        };
    }

    /** ¿Hay sesión de oficina? (para el blade, por defensa en profundidad). */
    public function officeScope(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && method_exists($user, 'hasAnyRole');
    }
}
