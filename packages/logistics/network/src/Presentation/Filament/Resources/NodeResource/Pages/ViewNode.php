<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\NodeHub;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Network\Presentation\Filament\Resources\LegResource;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;

/**
 * "Hub 360" de un nodo de la red: cockpit de detalle que agrega su ficha (código, tipo, departamento,
 * ubicación en mapa), la flota con base en él, los envíos destinados, los muelles de recepción y sus
 * conexiones con otros nodos. Datos cross-context vía {@see NodeHub} (tolerante). Usa una vista propia con
 * la capa `.distrack-cockpit` en lugar del infolist CRUD. Conserva el chrome de página Filament.
 *
 * La única escritura disponible sigue siendo la baja (DeactivateNodeCommand) como acción de cabecera.
 */
final class ViewNode extends ViewRecord
{
    use HasEntityNotes;

    protected static string $resource = NodeResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acciones).
    protected string $view = 'filament.network.node-hub';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            NodeResource::deactivateAction(),
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
        return app(NodeHub::class)->overview((string) $this->getRecord()->getKey());
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

    /** Tipo lógico de la entidad para las notas/seguimientos del hub. */
    protected function noteSubjectType(): string
    {
        return 'node';
    }

    /**
     * Enlaces de «Gestión del nodo»: registros que dependen del nodo (tramos de corredor) que ya no viven en
     * el menú; su hogar es esta ficha. Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $out = [];
        try {
            // Tramos que TOCAN este nodo (?node=<id>): el recurso resuelve from_node_id O to_node_id.
            $url = LegResource::getUrl('index').'?node='.rawurlencode((string) $this->getRecord()->getKey());
            $out[] = ['label' => 'Tramos de corredor', 'url' => $url];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }

        return $out;
    }
}
