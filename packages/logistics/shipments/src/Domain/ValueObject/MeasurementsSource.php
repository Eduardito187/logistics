<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * De dónde salieron las medidas de una línea de envío (cascada de Roadmap 02, Fase 2.1):
 *
 * - `product`: peso y volumen del propio producto del catálogo.
 * - `category`: al menos un valor vino de los defaults de la categoría del producto.
 * - `default`: al menos un valor cayó al default global (dato incompleto → se marca, nunca bloquea).
 *
 * Se persiste en `shipment_lines.measurements_source` (varchar(16)).
 */
enum MeasurementsSource: string
{
    case Product = 'product';
    case Category = 'category';
    case Default = 'default';

    /**
     * Rango de confianza (mayor = más confiable). Sirve para quedarse con la fuente más débil.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Product => 3,
            self::Category => 2,
            self::Default => 1,
        };
    }

    /**
     * La menos confiable de dos fuentes (la que define el flag de la línea).
     */
    public function weakest(self $other): self
    {
        return $other->rank() < $this->rank() ? $other : $this;
    }
}
