<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages;

use App\Support\Cockpit\SellerHub;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource;
use Throwable;

/**
 * "Hub 360" de un vendedor (seller del marketplace): cockpit de detalle que agrega su ficha (razón social,
 * NIT, estado, contacto) y la actividad de LIQUIDACIONES (pendientes/pagadas, montos). Datos cross-context
 * vía {@see SellerHub} (tolerante). Usa una vista propia con la capa `.distrack-cockpit` en lugar del
 * infolist CRUD. Las liquidaciones del vendedor se gestionan DESDE acá (sección «Gestión del vendedor»),
 * no como un ítem suelto del menú.
 */
final class ViewSeller extends ViewRecord
{
    protected static string $resource = SellerResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament.
    protected string $view = 'filament.sellers.seller-hub';

    // Título por la razón social, no un genérico ni el UUID.
    public function getTitle(): string
    {
        $name = $this->getRecord()->getAttribute('legal_name');

        return is_string($name) && $name !== '' ? $name : 'Vendedor';
    }

    /**
     * Datos agregados del hub (cross-context), memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(SellerHub::class)->overview((string) $this->getRecord()->getKey());
    }

    /**
     * Enlaces de «Gestión del vendedor»: los registros que DEPENDEN del vendedor (liquidaciones) que ya no
     * viven en el menú; su hogar es esta ficha. Abre la lista filtrada a este vendedor. Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $id = (string) $this->getRecord()->getKey();

        $out = [];
        try {
            $out[] = [
                'label' => 'Liquidaciones del vendedor',
                'url' => SellerSettlementResource::getUrl('index').'?seller='.rawurlencode($id),
            ];
        } catch (Throwable) {
            // recurso no disponible: se omite.
        }
        // ASN (avisos de recepción) del inbound de este vendedor: lista acotada por `?seller=<id>`.
        try {
            $out[] = [
                'label' => 'ASNs de recepción',
                'url' => AsnResource::getUrl('index').'?seller='.rawurlencode($id),
            ];
        } catch (Throwable) {
            // recurso no disponible: se omite.
        }

        return $out;
    }
}
