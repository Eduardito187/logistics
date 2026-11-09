<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\ShipmentPipeline as PipelineData;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Throwable;

/**
 * Pipeline de última milla: tablero que hace VISIBLE el flujo operativo de los envíos por estado del ciclo
 * de vida (recibido → planificado → en ejecución → entregado / fallido), en columnas con tarjetas
 * clickeables al hub del envío. Deja de ser una lista plana: se ve dónde se acumula el trabajo y qué está
 * atrasado (ventana vencida, marcado en rojo). Datos reales y tolerantes vía App\Support\Cockpit (grupo de
 * olas "workflows visibles"). Solo alcance de oficina (administrador/supervisor); el admin ve todo.
 */
final class ShipmentPipeline extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    // Primero del grupo de operaciones (tablero de entrada).
    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.shipment-pipeline';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Shipment pipeline');
    }

    public function getTitle(): string
    {
        return __('Shipment pipeline');
    }

    // El encabezado propio del cockpit hace de título; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Tablero de operaciones: solo cuentas con alcance de oficina (administrador/supervisor). Un rol de
     * campo opera desde sus propias secciones, no desde la vista supervisora global.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Columnas del pipeline (estado → tarjetas), memoizadas durante el request de Livewire.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function columns(): array
    {
        return app(PipelineData::class)->columns();
    }

    /** Enlace al hub del envío; '#' si no se puede resolver. */
    public function shipmentUrl(string $id): string
    {
        try {
            return ShipmentResource::getUrl('view', ['record' => $id]);
        } catch (Throwable) {
            return '#';
        }
    }
}
