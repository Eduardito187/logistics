<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\CockpitPulse;
use App\Support\Cockpit\CockpitTrends;
use App\Support\Cockpit\DriverHome;
use App\Support\Cockpit\FlaggedRoute;
use App\Support\Cockpit\OperationsActionService;
use App\Support\Cockpit\OperationsBrief;
use App\Support\Cockpit\StoreHome;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;
use Throwable;

/**
 * Torre de control: el landing operativo del admin. Deja de ser una grilla de widgets para volverse una
 * SALA DE OPERACIONES ("cockpit"): saludo + pulso de KPIs, la ruta señalada que necesita acción, el
 * resumen de operaciones (envíos en riesgo, causas y acciones que requieren tu aprobación) y el mapa de
 * la red. Todo sobre datos reales (ver App\Support\Cockpit\*), degradando con elegancia si falta una fuente.
 *
 * Es una superficie "andén" clara y cálida sobre la barra lateral Noche del panel (ver theme.css
 * `.distrack-cockpit`): el riel oscuro + el tablero claro, como la referencia de diseño.
 *
 * Página propia (no Dashboard de widgets) en la ruta home '/': se sirve en `/admin` y es el destino del
 * login (Filament cae a `url('/admin')`). Refresca por polling de Livewire (wire:poll) y despacha la
 * aprobación de acciones por el CommandBus (con asiento de auditoría). Solo presentación: lee por los
 * servicios de cockpit (tolerantes y cacheados) y no toca dominio ni infraestructura directamente.
 */
final class ControlTower extends Page
{
    // Home del panel: primer destino tras el login (servida en /admin).
    protected static string $routePath = '/';

    // Primero en la navegación, como landing.
    protected static ?int $navigationSort = -2;

    // Icono operativo (heroicons v2): señal/monitoreo.
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-signal';

    // Vista propia del cockpit.
    protected string $view = 'filament.pages.control-tower';

    /**
     * Ids de propuestas ya aprobadas en esta sesión de pantalla (para mostrar el estado "aprobada").
     *
     * @var list<string>
     */
    public array $approvedProposals = [];

    public static function getNavigationLabel(): string
    {
        return __('Control Tower');
    }

    public function getTitle(): string
    {
        return __('Control Tower');
    }

    // El saludo del cockpit hace de encabezado; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    // El ancho COMPLETO ya es el del panel (maxContentWidth(Width::Full) en AdminPanelProvider); no se
    // sobreescribe aquí para no duplicar la decisión.

    /**
     * Landing universal del panel (ruta '/'): cualquier cuenta que pueda entrar al admin. El CONTENIDO
     * operativo global (pulso, brief, ruta señalada) se muestra solo con alcance de operaciones
     * (administrador/supervisor); un rol de campo ve un saludo reducido y opera desde sus secciones.
     */
    public static function canAccess(): bool
    {
        return Auth::user() instanceof User;
    }

    /** ¿La cuenta tiene alcance operativo global (ve el cockpit completo)? */
    public function hasOpsScope(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Home adaptado al rol de campo del usuario (Fase 4): chofer (vinculado por users.driver_id) o tienda
     * (users.node_id). Null si no tiene entidad ligada (preparador/transportista) → el blade cae al home
     * genérico. Se llama una sola vez desde la vista.
     *
     * @return array{type: string, data: array<string, mixed>}|null
     */
    public function roleHome(): ?array
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return null;
        }

        if (is_string($user->driver_id) && $user->driver_id !== '') {
            return ['type' => 'driver', 'data' => app(DriverHome::class)->overview($user->driver_id)];
        }

        if (is_string($user->node_id) && $user->node_id !== '') {
            return ['type' => 'store', 'data' => app(StoreHome::class)->overview($user->node_id)];
        }

        return null;
    }

    /**
     * Tendencias de la operación (Fase 5 — "datos que se ven"): series diarias y distribución por estado
     * para los gráficos de la Torre de Control. Solo se usa en la vista de oficina. Se llama una vez.
     *
     * @return array{received: array{labels: list<string>, values: list<int>}, delivered: array{labels: list<string>, values: list<int>}, status: list<array{label: string, count: int, tone: string}>, totals: array{received: int, delivered: int}}
     */
    public function trends(): array
    {
        $trends = app(CockpitTrends::class);

        return [
            'received' => $trends->receivedPerDay(),
            'delivered' => $trends->deliveredPerDay(),
            'status' => $trends->statusDistribution(),
            'totals' => $trends->totals(),
        ];
    }

    /** Nombre corto para el saludo (primer nombre si lo hay). */
    public function greetingName(): string
    {
        $user = Auth::user();
        $name = $user instanceof User ? trim((string) $user->name) : '';

        if ($name === '') {
            return 'equipo';
        }

        return explode(' ', $name)[0];
    }

    /** Saludo por hora del día en la zona de la plataforma. */
    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Buen día',
            $hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /** Eyebrow con la fecha larga local (p. ej. "SATURDAY, OCTOBER 3"). */
    public function todayLabel(): string
    {
        return Carbon::now()->isoFormat('dddd, MMMM D');
    }

    /**
     * Pulso de KPIs (tiles). Se recomputa en cada poll; las lecturas están cacheadas ~20s.
     *
     * @return list<array<string, mixed>>
     */
    public function pulse(): array
    {
        return app(CockpitPulse::class)->metrics();
    }

    /**
     * Resumen de operaciones: en riesgo, en plan, causas y propuestas que requieren aprobación.
     *
     * @return array<string, mixed>
     */
    public function brief(): array
    {
        return app(OperationsBrief::class)->summary();
    }

    /**
     * Ruta señalada que necesita acción ahora (peor incumplimiento de ventana), o null si la red va en plan.
     *
     * @return array<string, mixed>|null
     */
    public function flagged(): ?array
    {
        return app(FlaggedRoute::class)->current();
    }

    /**
     * Enlace PÚBLICO de seguimiento de un envío (página `t/{token}`), para compartirlo con el cliente.
     * Tolerante: null si el envío no tiene seguimiento abierto todavía o no se pudo resolver el token.
     */
    public function shareTrackingUrl(string $shipmentId): ?string
    {
        if (trim($shipmentId) === '') {
            return null;
        }

        try {
            $token = DB::table('tracking_shipments')
                ->where('shipment_id', $shipmentId)
                ->value('token');

            if (!is_string($token) || $token === '') {
                return null;
            }

            return route('tracking.public.page', ['token' => $token]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Aprueba una propuesta del brief: despacha el comando real (notificar / replanificar / liberar cupo)
     * con su asiento de auditoría, y avisa el resultado. Tolerante: si falla, no rompe la pantalla.
     */
    public function approveProposal(string $proposalId): void
    {
        $user = Auth::user();
        if (!$user instanceof User || !$this->hasOpsScope()) {
            Notification::make()->title('No permitido')->danger()->send();

            return;
        }

        $result = app(OperationsActionService::class)->approve($proposalId, $user);

        $ok = (bool) ($result['ok'] ?? false);
        $message = (string) ($result['message'] ?? ($ok ? 'Listo' : 'No se pudo aplicar la acción'));

        if ($ok && !\in_array($proposalId, $this->approvedProposals, true)) {
            $this->approvedProposals[] = $proposalId;
        }

        Notification::make()
            ->title($message)
            ->status($ok ? 'success' : 'danger')
            ->send();
    }

    /**
     * Datos del mini-mapa de la red: centro, zoom máximo y posiciones vivas de la flota como puntos
     * (lat/lng/moving), SIN ids ni placas (nada de UUID; el detalle vive en el mapa de flota completo).
     * Tolerante: si telemetría no responde, devuelve la lista vacía y el mapa queda centrado sin puntos.
     *
     * @return array{center: array{0: float, 1: float}, max_zoom: int, positions: list<array{lat: float, lng: float, moving: bool}>}
     */
    public function mapData(): array
    {
        // Centro por defecto: eje troncal Bolivia (La Paz). El JS reajusta a los puntos si los hay.
        $default = ['center' => [-16.5, -68.15], 'max_zoom' => (int) config('maps.tile_max_zoom', 19), 'positions' => []];

        try {
            /** @var iterable<object> $views */
            $views = app(QueryBus::class)->ask(new GetLiveFleetPositions);

            $positions = [];
            foreach ($views as $view) {
                if (!isset($view->lat, $view->lng)) {
                    continue;
                }
                $positions[] = [
                    'lat' => (float) $view->lat,
                    'lng' => (float) $view->lng,
                    'moving' => (bool) ($view->moving ?? false),
                ];
            }

            $default['positions'] = $positions;
        } catch (Throwable) {
            // Telemetría ausente en este entorno: mapa sin puntos, nunca rompe el landing.
        }

        return $default;
    }

    /** URL de "Nuevo envío" (crear en el recurso de envíos), o null si el recurso no está disponible. */
    public function newShipmentUrl(): ?string
    {
        return $this->resourceUrl(ShipmentResource::class, 'create')
            ?? $this->resourceUrl(ShipmentResource::class, 'index');
    }

    /** URL del mapa de flota en vivo (seguimiento completo), o null si no está disponible. */
    public function liveMapUrl(): ?string
    {
        try {
            $page = LiveFleetMap::class;

            return class_exists($page) ? $page::getUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resuelve de forma tolerante la URL de una página de un Resource de Filament; null si no existe o la
     * ruta no está registrada (p. ej. el recurso no habilita esa página).
     *
     * @param class-string $resource
     */
    private function resourceUrl(string $resource, string $page): ?string
    {
        try {
            return class_exists($resource) ? $resource::getUrl($page) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
