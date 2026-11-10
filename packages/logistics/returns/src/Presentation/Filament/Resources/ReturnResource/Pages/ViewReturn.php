<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages;

use App\Support\Cockpit\ReturnHub;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource;

/**
 * "Hub 360" de una devolución (RMA): cockpit de detalle que agrega su ficha (referencia, tipo, estado del
 * ciclo, disposición, almacén) y sus líneas (productos devueltos). Datos reales y tolerantes vía
 * {@see ReturnHub}. Usa una vista propia con la capa `.distrack-cockpit` en lugar del infolist CRUD.
 * Conserva el chrome de página Filament (cabecera + acciones de ciclo: recibir, disponer).
 */
final class ViewReturn extends ViewRecord
{
    protected static string $resource = ReturnResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acciones).
    protected string $view = 'filament.returns.return-hub';

    // Título por la referencia original de la devolución, no el UUID.
    public function getTitle(): string
    {
        $ref = $this->getRecord()->getAttribute('original_reference');

        return is_string($ref) && $ref !== '' ? $ref : 'Devolución';
    }

    /**
     * Datos agregados del hub, memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(ReturnHub::class)->overview((string) $this->getRecord()->getKey());
    }
}
