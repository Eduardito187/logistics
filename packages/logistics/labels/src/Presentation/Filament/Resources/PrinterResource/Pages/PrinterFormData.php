<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages;

/**
 * Normalización compartida de los campos opcionales del formulario de impresora (alta y edición).
 */
final class PrinterFormData
{
    public static function optional(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
