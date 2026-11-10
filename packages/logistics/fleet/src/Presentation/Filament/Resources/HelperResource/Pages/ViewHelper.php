<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\HelperHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Throwable;

/**
 * "Hub 360" de un ayudante: cockpit de detalle que agrega sus datos personales (del registro), sus
 * documentos con vencimiento y las tripulaciones en las que participa. Datos cross-context vía
 * {@see HelperHub} (tolerante). Usa una vista propia con la capa `.distrack-cockpit` en lugar del infolist
 * CRUD. El ayudante es una persona operativa como el chofer, pero sin licencia. Desde la cabecera se edita
 * el perfil y se registran documentos.
 */
final class ViewHelper extends ViewRecord
{
    use HasEntityNotes;

    protected static string $resource = HelperResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acciones).
    protected string $view = 'filament.fleet.helper-hub';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('helper'),
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
        return app(HelperHub::class)->overview((string) $this->getRecord()->getKey());
    }

    /**
     * Línea de tiempo (actividad) del ayudante, memoizada durante el request.
     *
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(EntityTimeline::class)->forIds([(string) $this->getRecord()->getKey()]);
    }

    /**
     * URL servible de la foto del ayudante guardada en disco, o null; nunca se ve rota (fallback en la vista).
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
        return 'helper';
    }

    /**
     * Enlaces de «Gestión del ayudante»: registros que dependen del ayudante (documentos) que ya no viven en
     * el menú; su hogar es esta ficha. El enlace abre la lista FILTRADA al ayudante (`?helper=<id>`, que el
     * recurso resuelve por owner_type/owner_id). Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $id = (string) $this->getRecord()->getKey();

        $out = [];
        try {
            $out[] = ['label' => 'Documentos', 'url' => DocumentResource::getUrl('index').'?helper='.rawurlencode($id)];
        } catch (Throwable) {
            // recurso no disponible: se omite.
        }

        return $out;
    }
}
