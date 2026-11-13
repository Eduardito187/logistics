<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Alcance (base del cálculo) del vale: una línea, un envío completo o toda la orden (envíos hermanos).
 */
enum CompensationScope: string
{
    case Line = 'LINE';
    case Shipment = 'SHIPMENT';
    case Order = 'ORDER';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value)))
            ?? throw CompensationException::because("Alcance de compensación inválido: {$value}.");
    }
}
