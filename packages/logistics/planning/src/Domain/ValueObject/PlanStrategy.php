<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Modo del auto-planificador diario. Es un contrato de negocio, no un detalle técnico: define CÓMO se
 * arman los camiones a partir de los pedidos pendientes.
 *
 * - Optimization: empaqueta por capacidad y cercanía (bin-packing por volumen, agrupando por zona) y
 *   rutea cada camión por el orden óptimo de paradas.
 * - Slots: respeta la FRANJA horaria elegida por el cliente; agrupa por (fecha, franja, departamento)
 *   antes de empaquetar, y rutea cada camión dentro de su franja.
 *
 * En ambos modos la regla geográfica es inamovible: un camión entrega a UN SOLO departamento.
 */
enum PlanStrategy: string
{
    case Optimization = 'optimization';

    case Slots = 'slots';

    /**
     * Construye desde el texto del comando/API, con un error de dominio claro si no es válido.
     */
    public static function fromString(string $value): self
    {
        $strategy = self::tryFrom(trim($value));
        if ($strategy === null) {
            throw PlanningException::because("estrategia de planificación inválida: {$value} (optimization|slots)");
        }

        return $strategy;
    }

    /**
     * Etiqueta en inglés para el admin y las respuestas de la API (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Optimization => 'Optimization (capacity + proximity)',
            self::Slots => 'Delivery slots',
        };
    }
}
