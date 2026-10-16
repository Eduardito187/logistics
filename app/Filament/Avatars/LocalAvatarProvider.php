<?php

declare(strict_types=1);

namespace App\Filament\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Proveedor de avatar por defecto del panel, sin salir a internet.
 *
 * El proveedor de Filament por defecto (`UiAvatarsProvider`) devuelve una URL a `ui-avatars.com`, que
 * offline (o con la CSP del admin cerrada a orígenes externos) queda como imagen rota en la barra
 * superior. Aquí generamos un avatar con las iniciales del usuario como SVG embebido en un data URI:
 * no hace ninguna petición externa y siempre carga, coherente con el stack sin servicios de terceros
 * (ADR 013) y con el patrón de placeholders de `Logistics\Shared\...\ImageFallback`.
 */
final class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/u', $name) ?: [])
            ->map(function (string $segment): string {
                // Saltar puntuación inicial (p. ej. "[SYSTEM] Admin") para que no entre en las iniciales.
                $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment) ?? '';

                return $letters === '' ? '' : mb_strtoupper(mb_substr($letters, 0, 1));
            })
            ->filter()
            ->take(2)
            ->implode('');

        if ($initials === '') {
            $initials = '?';
        }

        // Fondo gris de marca (mismo neutro que usa el panel) y texto blanco.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">'
            .'<rect width="80" height="80" fill="#1f2937"/>'
            .'<text x="40" y="40" dy=".35em" fill="#ffffff" font-family="Open Sans, Arial, sans-serif" '
            .'font-size="34" font-weight="600" text-anchor="middle">'.htmlspecialchars($initials, ENT_QUOTES).'</text>'
            .'</svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }
}
