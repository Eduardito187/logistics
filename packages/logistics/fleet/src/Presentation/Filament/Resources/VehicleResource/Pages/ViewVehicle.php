<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\VehicleHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource;
use Throwable;

/**
 * "Hub 360" de un vehículo: cockpit de detalle que agrega, en una sola pantalla, telemetría en vivo,
 * ruta/entregas de hoy, costos/utilización, documentos con vencimiento, alertas de mal uso e incidentes
 * (todo cross-context vía {@see VehicleHub}, tolerante). Deja de ser un infolist tipo CRUD: usa una vista
 * propia con la capa de diseño `.distrack-cockpit`. Desde la cabecera se edita la ficha y se registran
 * documentos (SOAT, inspección técnica, etc.).
 */
final class ViewVehicle extends ViewRecord
{
    use HasEntityNotes;

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
     * Línea de tiempo (actividad) del vehículo, memoizada durante el request.
     *
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(EntityTimeline::class)->forIds([(string) $this->getRecord()->getKey()]);
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

    /** Tipo lógico de la entidad para las notas/seguimientos del hub. */
    protected function noteSubjectType(): string
    {
        return 'vehicle';
    }

    /**
     * Enlaces de «Gestión del vehículo»: los registros que DEPENDEN del vehículo (mantenimiento, odómetro,
     * combustible, salidas, gastos, checklists, ficha técnica) que ya no viven en el menú. Su hogar es esta
     * ficha: cada enlace abre la lista del recurso ya FILTRADA a este vehículo (por su filtro `vehicle_id`),
     * donde existe. Tolerante: si un recurso no está disponible, se omite sin romper la vista.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $id = (string) $this->getRecord()->getKey();

        /** @var list<array{0: string, 1: class-string<\Filament\Resources\Resource>, 2: bool}> $items */
        $items = [
            ['Mantenimiento', MaintenanceOrderResource::class, true],
            ['Plan de mantenimiento', MaintenancePlanResource::class, true],
            ['Odómetro', OdometerReadingResource::class, true],
            ['Combustible', RefuelResource::class, true],
            ['Salidas', DepartureResource::class, true],
            ['Gastos', ExpenseResource::class, true],
            ['Recorridos', TripResource::class, true],
            ['Checklists pre-salida', PreTripChecklistResource::class, true],
            ['Ficha técnica', VehicleProfileResource::class, true],
            ['Tripulaciones', CrewResource::class, true],
        ];

        $out = [];
        foreach ($items as [$label, $resource, $filtered]) {
            try {
                $url = $resource::getUrl('index');
                if ($filtered) {
                    // ?vehicle=<id> + RequestScopedQuery::by('vehicle','vehicle_id') en el recurso (el deep-link
                    // ?tableFilters[...] de Filament no hidrata el filtro desde la URL en esta versión).
                    $url .= '?vehicle='.rawurlencode($id);
                }
                $out[] = ['label' => $label, 'url' => $url];
            } catch (Throwable) {
                // recurso no disponible en este entorno: se omite.
            }
        }

        return $out;
    }
}
