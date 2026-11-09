<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cockpit;

use App\Enums\Role;
use App\Filament\Pages\CustomerProfile;
use App\Models\User;
use App\Support\Cockpit\GlobalSearch;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Throwable;

/**
 * Endpoint JSON del command palette (⌘K): busca entidades-eje y devuelve resultados agrupados con su
 * enlace. Se consume por fetch (mismo origen) desde el palette Alpine; evita el round-trip de Livewire
 * por tecla (más rápido y sin problemas de CSRF/sesión). Solo alcance de OFICINA (admin/supervisor): el
 * administrador accede a todo. NUNCA expone un UUID como texto visible; el id solo arma el enlace.
 *
 * En la Fase 1 enlaza a la pantalla de cada recurso (view). En la Fase 2 se repunta a los hubs 360.
 */
final class SearchController
{
    /** Mapa tipo → clase del Resource de Filament para resolver el enlace. */
    private const RESOURCES = [
        'vehicle' => VehicleResource::class,
        'driver' => DriverResource::class,
        'shipment' => ShipmentResource::class,
        'node' => NodeResource::class,
        'carrier' => CarrierResource::class,
        'order' => OrderResource::class,
    ];

    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            abort(403);
        }

        $items = $search->search((string) $request->query('q', ''));

        $byGroup = [];
        foreach ($items as $item) {
            $byGroup[$item['group']][] = [
                'title' => $item['title'],
                'subtitle' => $item['subtitle'],
                'icon' => $item['icon'],
                'url' => $this->url((string) $item['type'], (string) $item['id']),
            ];
        }

        $groups = [];
        foreach ($byGroup as $group => $rows) {
            $groups[] = ['group' => (string) $group, 'items' => $rows];
        }

        return response()->json(['groups' => $groups]);
    }

    /** Enlace a la pantalla de la entidad (view → edit → index); null si no se puede resolver. */
    private function url(string $type, string $id): ?string
    {
        // Cliente: no es un Resource; su ficha 360 se arma por nombre (el id del resultado es el nombre).
        if ($type === 'customer') {
            try {
                return CustomerProfile::urlFor($id);
            } catch (Throwable) {
                return null;
            }
        }

        $resource = self::RESOURCES[$type] ?? null;
        if ($resource === null || !class_exists($resource)) {
            return null;
        }

        foreach (['view', 'edit', 'index'] as $page) {
            try {
                return $page === 'index'
                    ? $resource::getUrl('index')
                    : $resource::getUrl($page, ['record' => $id]);
            } catch (Throwable) {
                // intenta la siguiente página
            }
        }

        return null;
    }
}
