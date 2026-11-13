<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Disparador que originó la compensación (qué problema de entrega la genera).
 */
enum TriggerType: string
{
    case DeliveryFailed = 'DELIVERY_FAILED';
    case LateDelivery = 'LATE_DELIVERY';
    case DamagedProduct = 'DAMAGED_PRODUCT';
    case Incomplete = 'INCOMPLETE';
    case LowRating = 'LOW_RATING';
    case Manual = 'MANUAL';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value)))
            ?? throw CompensationException::because("Disparador de compensación inválido: {$value}.");
    }
}
