<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages;

use App\Support\Cockpit\ProductHub;
use Commerce\Catalog\Presentation\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;

/**
 * "Hub 360" de un producto del catálogo: cockpit de detalle que agrega su ficha (nombre, SKU, categoría,
 * vendedor), variantes, inventario on-hand, precios vigentes y órdenes recientes que lo contienen. Datos
 * cross-context (catalog + inventory + pricing + orders) vía {@see ProductHub}, tolerante. Usa una vista
 * propia con la capa `.distrack-cockpit` en lugar del infolist CRUD.
 */
final class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament.
    protected string $view = 'filament.catalog.product-hub';

    // Título por el nombre del producto, no un genérico ni el UUID.
    public function getTitle(): string
    {
        $name = $this->getRecord()->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Producto';
    }

    /**
     * Datos agregados del hub (cross-context), memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(ProductHub::class)->overview((string) $this->getRecord()->getKey());
    }
}
