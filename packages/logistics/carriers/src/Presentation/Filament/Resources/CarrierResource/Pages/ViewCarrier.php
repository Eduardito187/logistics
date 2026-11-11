<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages;

use App\Support\Cockpit\CarrierHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource;
use Throwable;

/**
 * "Hub 360" de un transportista: cockpit de detalle que agrega su ficha (estado, calificación), sus
 * liquidaciones (pendientes/monto) y sus tarifas por corredor (todo vía {@see CarrierHub}, tolerante). Deja
 * de ser un infolist tipo CRUD: usa una vista propia con la capa `.distrack-cockpit`. Las tarifas y
 * liquidaciones del transportista se gestionan DESDE acá (sección «Gestión del transportista»), no como
 * ítems sueltos del menú.
 */
final class ViewCarrier extends ViewRecord
{
    protected static string $resource = CarrierResource::class;

    // Vista propia (cockpit) en lugar del infolist estándar; conserva el chrome de página Filament.
    protected string $view = 'filament.carriers.carrier-hub';

    // Oculta el <h1> de Filament: el hero del cockpit (blade) ya muestra el título; así no se ve duplicado.
    public function getHeading(): string
    {
        return '';
    }

    // Título por el nombre del transportista, nunca el UUID.
    public function getTitle(): string
    {
        $name = $this->getRecord()->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Transportista';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    /**
     * Datos agregados del hub, memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(CarrierHub::class)->overview((string) $this->getRecord()->getKey());
    }

    /**
     * Enlaces de «Gestión del transportista»: tarifas y liquidaciones que DEPENDEN del transportista y ya no
     * viven en el menú; su hogar es esta ficha. Cada enlace abre la lista ya FILTRADA a este transportista
     * (por su filtro `carrier_id`). Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $id = (string) $this->getRecord()->getKey();

        /** @var list<array{0: string, 1: class-string<\Filament\Resources\Resource>}> $items */
        $items = [
            ['Tarifas', TariffResource::class],
            ['Liquidaciones', SettlementResource::class],
        ];

        $out = [];
        foreach ($items as [$label, $resource]) {
            try {
                // Scope por `?carrier=<id>` (lo aplica el `modifyQueryUsing` del recurso). NO se usa el
                // deep-link `tableFilters[...]` de Filament porque en esta versión no aplica el filtro.
                $out[] = ['label' => $label, 'url' => $resource::getUrl('index').'?carrier='.rawurlencode($id)];
            } catch (Throwable) {
                // recurso no disponible en este entorno: se omite.
            }
        }

        return $out;
    }
}
