<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Lenguaje de impresión de una impresora de red. `zpl` = Zebra (la app le manda el ZPL de la etiqueta
 * por el puerto RAW); `other` = cualquier otra (Ricoh, láser) que la app no imprime con ZPL.
 */
enum PrinterLanguage: string
{
    case Zpl = 'zpl';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Zpl => 'ZPL (Zebra)',
            self::Other => 'Other',
        };
    }
}
