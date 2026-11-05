<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use App\Support\Cockpit\DriverHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\ImageFallback;

/**
 * "Hub 360" de un chofer: cockpit de detalle que agrega su desempeño operativo (entregas, efectividad,
 * días y vehículos del periodo), su tripulación de hoy, datos personales y referencias, licencia y
 * documentos con vencimiento, y alertas de mal uso. Datos cross-context vía {@see DriverHub} (tolerante).
 * Usa una vista propia con la capa de diseño `.distrack-cockpit` en lugar del infolist CRUD. Desde la
 * cabecera se edita el perfil y se registran documentos.
 */
final class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acciones).
    protected string $view = 'filament.fleet.driver-hub';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('driver'),
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
        return app(DriverHub::class)->overview((string) $this->getRecord()->getKey());
    }

    /**
     * URL servible de la foto del chofer guardada en disco, o null; nunca se ve rota (fallback en la vista).
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
