<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Cockpit\SettlementsBoard;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Cockpit de LIQUIDACIONES (CRM financiero): unifica en una sola vista las liquidaciones de transportistas
 * y de vendedores —que antes eran dos recursos sueltos— con sus KPIs por estado, el total «por pagar» de
 * cada frente y una lista reciente enlazada a cada ficha. Les da un hogar común sin perder el detalle: cada
 * fila sigue abriéndose en su Resource (donde viven las acciones del ciclo: aprobar, pagar, objetar…).
 *
 * Página cockpit (`.distrack-cockpit`, español directo), SOLO lectura, alcance de oficina.
 */
final class SettlementsCockpit extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.settlements-cockpit';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationLabel(): string
    {
        return 'Liquidaciones';
    }

    public function getTitle(): string
    {
        return 'Liquidaciones';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Overview financiero de ambos frentes (compuesto por el read-service, en primitivos y tolerante).
     *
     * @return array{carrier: array<string, mixed>, seller: array<string, mixed>}
     */
    #[Computed]
    public function board(): array
    {
        return app(SettlementsBoard::class)->overview();
    }

    /** Enlace al índice completo de liquidaciones de transportistas («ver todas»). */
    public function carrierIndexUrl(): ?string
    {
        try {
            return SettlementResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Enlace al índice completo de liquidaciones de vendedores («ver todas»). */
    public function sellerIndexUrl(): ?string
    {
        try {
            return SellerSettlementResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }
}
