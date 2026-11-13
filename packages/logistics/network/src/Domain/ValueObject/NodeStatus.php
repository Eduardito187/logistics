<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado operativo de un nodo. Un nodo inactivo sigue existiendo (histórico, auditoría) pero no
 * debería recibir ni despachar carga nueva; esa regla la aplica planning, no network.
 */
enum NodeStatus: string
{
    use CaseInsensitiveEnum;

    case Active = 'active';

    case Inactive = 'inactive';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
