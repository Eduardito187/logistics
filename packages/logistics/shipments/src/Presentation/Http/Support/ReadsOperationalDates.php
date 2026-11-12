<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Lectura tolerante de parámetros de FECHA operativa del tablero (`date`, `date_from`, `date_to`).
 *
 * Acepta `YYYY-MM-DD` y también una fecha-hora ISO 8601 (algunos clientes generados serializan una fecha
 * como date-time): se queda con la fecha de calendario TAL COMO LA ESCRIBIÓ el cliente (los 10 primeros
 * caracteres, sin convertir zona). La validación posterior (`date_format:Y-m-d`) rechaza lo demás con 422.
 */
trait ReadsOperationalDates
{
    /**
     * Normaliza in situ los parámetros de fecha dados (si traen una fecha-hora ISO, se recorta a la fecha).
     *
     * @param list<string> $keys
     */
    private function normalizeDateParameters(Request $request, array $keys): void
    {
        $normalized = [];
        foreach ($keys as $key) {
            $raw = $request->query($key);
            if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', trim($raw)) === 1) {
                $normalized[$key] = substr(trim($raw), 0, 10);
            }
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    /** Hoy en America/La_Paz (día operativo por defecto). */
    private function operationalToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    }
}
