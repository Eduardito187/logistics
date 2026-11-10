<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\DispatchTimeline as TimelineData;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Línea de tiempo (Gantt) del DESPACHO del día (Fase 3 — workflows visibles): un carril por conductor con
 * las paradas de su ruta sobre un eje horario común. Complementa los pipelines kanban mostrando el FLUJO
 * temporal del día (quién sale, cuándo, qué pasó). Datos reales y tolerantes vía App\Support\Cockpit\
 * DispatchTimeline. Solo alcance de oficina (administrador/supervisor). Sin UUIDs en pantalla.
 */
final class DispatchTimeline extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-bars-arrow-down';

    // En Operaciones, junto a los pipelines (tablero de flujo).
    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.dispatch-timeline';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dispatch timeline');
    }

    public function getTitle(): string
    {
        return __('Dispatch timeline');
    }

    // El encabezado propio del cockpit hace de título; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Solo cuentas con alcance de oficina (administrador/supervisor).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Tablero Gantt del día (carriles + ventana del eje), memoizado durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function board(): array
    {
        return app(TimelineData::class)->board();
    }
}
