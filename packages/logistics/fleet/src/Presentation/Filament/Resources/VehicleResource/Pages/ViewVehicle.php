<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use App\Support\Cockpit\VehicleHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\ImageFallback;

/**
 * "Hub 360" de un vehículo: cockpit de detalle que agrega, en una sola pantalla, telemetría en vivo,
 * ruta/entregas de hoy, costos/utilización, documentos con vencimiento, alertas de mal uso e incidentes
 * (todo cross-context vía {@see VehicleHub}, tolerante). Deja de ser un infolist tipo CRUD: usa una vista
 * propia con la capa de diseño `.distrack-cockpit`. Desde la cabecera se edita la ficha y se registran
 * documentos (SOAT, inspección técnica, etc.).
 */
final class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    // Vista propia (cockpit) en lugar del infolist estándar; conserva el chrome de página Filament.
    protected string $view = 'filament.fleet.vehicle-hub';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('vehicle'),
        ];
    }

    /**
     * Datos agregados del hub (cross-context), memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(VehicleHub::class)->overview((string) $this->getRecord()->getKey());
    }

    /**
     * URL servible de una foto del vehículo guardada en disco, o null; nunca se ve rota (fallback en la vista).
     */
    public function photoUrl(?string $path): ?string
    {
        return ImageFallback::diskUrl($this->documentsDisk(), $path);
    }

    /** Disco donde viven las fotos/archivos de flota (configurable por env). */
    private function documentsDisk(): string
    {
        $disk = config('fleet.documents_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
