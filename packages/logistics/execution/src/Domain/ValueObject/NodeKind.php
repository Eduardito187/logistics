<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Tipo de nodo logístico a efectos de la recepción: centro de distribución o tienda. Es el dato mínimo
 * que necesita la ejecución para clasificar el corredor; no se acopla al catálogo completo de `network`.
 */
enum NodeKind: string
{
    use CaseInsensitiveEnum;

    /** Centro de distribución. */
    case Cd = 'CD';

    /** Tienda. */
    case Store = 'STORE';

    /**
     * Convierte un código de origen libre (p. ej. del cuerpo de la request) al tipo de nodo. Lanza si el
     * código no corresponde a un tipo conocido.
     */
    public static function fromCode(string $code): self
    {
        return self::tryFromLoose($code)
            ?? throw ExecutionException::because("tipo de nodo desconocido: {$code}");
    }
}
