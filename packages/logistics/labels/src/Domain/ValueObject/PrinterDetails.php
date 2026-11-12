<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use Logistics\Labels\Domain\Exception\InvalidPrinterException;

/**
 * Datos editables de una impresora de red (todo menos identidad y estado activo): nombre, modelo,
 * destino de red, lenguaje, sede y ubicación. Inmutable; normaliza (trim, vacío → null) y exige nombre.
 *
 * `nodeId` es una referencia opaca al nodo de Network (null = compartida, visible para todas las sedes).
 */
final readonly class PrinterDetails
{
    public string $name;

    public ?string $model;

    public ?string $nodeId;

    public ?string $location;

    public function __construct(
        string $name,
        ?string $model,
        public PrinterEndpoint $endpoint,
        public PrinterLanguage $language,
        ?string $nodeId = null,
        ?string $location = null,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw InvalidPrinterException::emptyName();
        }

        $this->name = $name;
        $this->model = self::optional($model);
        $this->nodeId = self::optional($nodeId);
        $this->location = self::optional($location);
    }

    private static function optional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
