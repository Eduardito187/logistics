<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Simbología que se imprime en la etiqueta: QR firmado, código de barras 1D, o ambos.
 *
 * Es una regla configurable y versionada (clave `labels.symbology` de `logistics/settings`):
 *  - `Qr`      solo el QR con el JSON firmado (lo que verifica la app offline);
 *  - `Barcode` solo un Code128 con el id corto del bulto (legible por lector láser 1D);
 *  - `Both`    ambos (por defecto): el QR firmado más el Code128 secundario.
 *
 * El QR nunca cambia su contenido: la simbología solo decide qué bloques se dibujan en el ZPL.
 */
enum LabelSymbology: string
{
    case Qr = 'qr';

    case Barcode = 'barcode';

    case Both = 'both';

    /** Valor usado cuando no hay regla publicada ni valor configurado: ambos. */
    public const LabelSymbology DEFAULT = self::Both;

    /**
     * Interpreta un valor primitivo (de settings o config) de forma tolerante: cualquier valor
     * desconocido, vacío o de tipo inesperado cae al valor por defecto, para que la etiqueta siempre
     * tenga una simbología con la que imprimirse.
     */
    public static function fromValue(mixed $value): self
    {
        if (!is_string($value)) {
            return self::DEFAULT;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::DEFAULT;
    }

    /** True si la etiqueta debe llevar el QR firmado. */
    public function includesQr(): bool
    {
        return $this === self::Qr || $this === self::Both;
    }

    /** True si la etiqueta debe llevar el código de barras Code128. */
    public function includesBarcode(): bool
    {
        return $this === self::Barcode || $this === self::Both;
    }
}
