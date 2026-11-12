<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Concerns;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lectura tolerante de un parámetro de FECHA operativa (auditoría #29).
 *
 * Acepta `YYYY-MM-DD` y también una fecha-hora ISO 8601 completa (`2026-11-12T00:00:00.000Z`,
 * `2026-11-12T08:30:00-04:00`, `2026-11-12 08:30:00`): algunos clientes generados serializan una fecha
 * como date-time. Se normaliza a la FECHA DE CALENDARIO TAL COMO LA ESCRIBIÓ EL CLIENTE (los primeros 10
 * caracteres), sin convertir zona horaria: un `DateTime(2026,11,12)` de la app viaja como
 * `2026-11-12T00:00:00.000Z` y debe seguir significando el 12, no el 11 en La Paz.
 *
 * Sin el parámetro, la fecha por defecto es HOY en America/La_Paz. Un valor inválido → 422 estándar.
 */
trait NormalizesDateParameter
{
    private function operationalDate(Request $request, string $key = 'date'): string
    {
        $raw = $request->query($key);

        if ($raw === null || $raw === '') {
            return (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
        }

        $date = is_string($raw) ? self::normalizeDateString($raw) : null;
        if ($date === null) {
            throw ValidationException::withMessages([
                $key => ["The {$key} field must be a date (YYYY-MM-DD) or an ISO 8601 date-time."],
            ]);
        }

        return $date;
    }

    /**
     * `YYYY-MM-DD` del valor (fecha o fecha-hora ISO 8601), o null si no es una fecha válida.
     */
    private static function normalizeDateString(string $value): ?string
    {
        $value = trim($value);
        // El offset admite espacio en lugar de «+»: un «+» sin codificar en la query string llega como espacio.
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(?:Z|[+\- ]\d{2}:?\d{2})?)?$/i';

        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $hour = (int) ($m[4] ?? '0');
        $minute = (int) ($m[5] ?? '0');
        $second = (int) ($m[6] ?? '0');
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
