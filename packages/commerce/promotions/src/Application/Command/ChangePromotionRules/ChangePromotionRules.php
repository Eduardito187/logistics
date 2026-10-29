<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ChangePromotionRules;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cambiar las reglas de una promoción (tipo, valor y condiciones). Incrementa su versión.
 *
 * - type: valor del enum PromotionType (percentage_off | fixed_amount_off | free_shipping).
 * - value: según el tipo (basis points 0..10000 para porcentaje, centavos para monto fijo, 0 para envío).
 * - channel: Retail | B2B | C2C; null = todos los canales.
 */
final readonly class ChangePromotionRules implements Command
{
    public function __construct(
        public string $promotionId,
        public string $type,
        public int $value,
        public int $minSubtotalCents = 0,
        public ?string $channel = null,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
