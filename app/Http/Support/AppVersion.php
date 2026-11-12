<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * Utilidades de versión semántica de la app (`MAJOR.MINOR.PATCH`).
 *
 * Acepta lo que envía la app en `X-App-Version` (p. ej. "1.4.2", "1.4.2+42", "v1.4") y lo normaliza a
 * tres componentes numéricos; cualquier otra cosa se considera "sin versión" (null), lo que hace que la
 * guarda de versión deje pasar el request (compatibilidad hacia atrás).
 */
final class AppVersion
{
    public static function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        if (preg_match('/^v?(\d{1,5})(?:\.(\d{1,5}))?(?:\.(\d{1,5}))?(?:[-+].*)?$/i', trim($version), $m) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', (int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0));
    }

    /**
     * ¿`$installed` es estrictamente menor que `$minimum`? Ambos deben venir normalizados.
     */
    public static function isBelow(string $installed, string $minimum): bool
    {
        return version_compare($installed, $minimum, '<');
    }
}
