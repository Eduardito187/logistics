<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Middleware de Inertia para la app Vue+Inertia (superficie orientada al cliente / operativa de campo),
 * SEPARADA del admin Filament. Comparte props globales a todas las páginas Vue y define la vista raíz.
 *
 * El admin interno sigue 100% en Filament (Livewire+Blade); esta app convive sin tocarlo.
 */
final class HandleInertiaRequests extends Middleware
{
    /**
     * Vista Blade raíz que monta la SPA (contiene @inertia y @vite del bundle Vue).
     */
    protected $rootView = 'app';

    /**
     * Props compartidas a TODAS las páginas Inertia. Nada de PII ni secretos: solo marca y flash.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name', 'DisTrack'),
            ],
            // Mensajes flash (éxito/error) para mostrarlos en la UI sin recargar.
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }
}
