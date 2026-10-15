<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Middleware de Inertia para la app web Vue (/app). Comparte datos globales con el front en cada
 * respuesta Inertia: el usuario autenticado (para auth/RBAC en el cliente) y los mensajes flash.
 * Las rutas de Ziggy se inyectan aparte con @routes en el blade raíz.
 */
final class HandleInertiaRequests extends Middleware
{
    /**
     * Plantilla raíz cargada en la primera visita (resources/views/app.blade.php).
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props compartidas por defecto en todas las páginas Inertia.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->getAuthIdentifier(),
                    'name' => $user->name,
                    'email' => $user->email,
                    // Roles (valores string del enum App\Enums\Role) para gating en el cliente.
                    'roles' => array_values(array_map(
                        static fn (Role $role): string => $role->value,
                        method_exists($user, 'roles') ? $user->roles() : [],
                    )),
                ],
            ],
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
            // Configuración de teselas de mapa (config/maps.php) para los mapas Leaflet del SPA. Se
            // comparte aquí para que RouteMap.vue use el tileserver OSM self-host del proyecto en vez de
            // una URL fija; vacío el self-host, el componente cae al tile público de OSM (solo demo).
            'maps' => [
                'tileUrl' => config('maps.tile_url'),
                'tileFallbackUrl' => config('maps.tile_fallback_url'),
                'tileAttribution' => config('maps.tile_attribution', '&copy; OpenStreetMap'),
                'tileMaxZoom' => (int) config('maps.tile_max_zoom', 19),
            ],
        ];
    }
}
