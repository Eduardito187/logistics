<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\DriverHub;
use App\Support\Cockpit\EntityTimeline;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Livewire\Attributes\Computed;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource;
use Logistics\Fleet\Presentation\Filament\Pages\DriverHistory;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Throwable;

/**
 * "Hub 360" de un chofer: cockpit de detalle que agrega su desempeño operativo (entregas, efectividad,
 * días y vehículos del periodo), su tripulación de hoy, datos personales y referencias, licencia y
 * documentos con vencimiento, y alertas de mal uso. Datos cross-context vía {@see DriverHub} (tolerante).
 * Usa una vista propia con la capa de diseño `.distrack-cockpit` en lugar del infolist CRUD. Desde la
 * cabecera se edita el perfil y se registran documentos.
 */
final class ViewDriver extends ViewRecord
{
    use HasEntityNotes;

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
     * Línea de tiempo (actividad) de la entidad, memoizada durante el request.
     *
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(EntityTimeline::class)->forIds([(string) $this->getRecord()->getKey()]);
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

    /** Tipo lógico de la entidad para las notas/seguimientos del hub. */
    protected function noteSubjectType(): string
    {
        return 'driver';
    }

    /**
     * Enlaces de «Gestión del chofer»: registros que dependen del chofer (tripulaciones, documentos) que ya
     * no viven en el menú; su hogar es esta ficha. Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        // Listas acotadas a ESTE chofer por `?driver=<id>` (lo resuelve el modifyQueryUsing de cada recurso:
        // Crew por driver_id; Document por owner_type='driver' + owner_id).
        $driver = '?driver='.rawurlencode((string) $this->getRecord()->getKey());

        /** @var list<array{0: string, 1: class-string<\Filament\Resources\Resource>}> $items */
        $items = [
            ['Tripulaciones', CrewResource::class],
            ['Rutas del chofer', VehicleRouteResource::class],
            ['Documentos', DocumentResource::class],
        ];

        $out = [];
        foreach ($items as [$label, $resource]) {
            try {
                $out[] = ['label' => $label, 'url' => $resource::getUrl('index').$driver];
            } catch (Throwable) {
                // recurso no disponible: se omite.
            }
        }

        // Desempeño operativo: es una Page (no un Resource), así que su URL no lleva 'index'. Preselecciona a
        // este chofer con `?driver=<id>` (lo lee DriverHistory::mount()).
        try {
            $out[] = [
                'label' => 'Desempeño operativo',
                'url' => DriverHistory::getUrl().$driver,
            ];
        } catch (Throwable) {
            // página no disponible: se omite.
        }

        return $out;
    }
}
