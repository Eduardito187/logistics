<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\OrderPipeline as PipelineData;
use BackedEnum;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Pipeline de pedidos (OMS): tablero que hace VISIBLE el flujo comercial de las órdenes por estado del
 * ciclo de vida (pendiente de pago → pagado → en preparación → cancelado), en columnas con tarjetas
 * clickeables al hub del pedido. El gemelo del pipeline de envíos para el frente de comercio. Datos reales
 * y tolerantes vía App\Support\Cockpit\OrderPipeline. Solo alcance de oficina (administrador/supervisor).
 */
final class OrderPipeline extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    // Primero del grupo de comercio (tablero de entrada).
    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.order-pipeline';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('Order pipeline');
    }

    public function getTitle(): string
    {
        return __('Order pipeline');
    }

    // El encabezado propio del cockpit hace de título; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Tablero de comercio: solo cuentas con alcance de oficina (administrador/supervisor).
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

    /** Enlace al hub del pedido; '#' si no se puede resolver. */
    public function orderUrl(string $id): string
    {
        try {
            return OrderResource::getUrl('view', ['record' => $id]);
        } catch (Throwable) {
            return '#';
        }
    }
}
