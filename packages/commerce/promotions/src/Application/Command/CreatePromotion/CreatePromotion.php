<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\CreatePromotion;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta una promoción nueva en borrador.
 *
 * - type: valor del enum PromotionType (percentage_off | fixed_amount_off | free_shipping).
 * - value: según el tipo (basis points 0..10000 para porcentaje, centavos para monto fijo, 0 para envío).
 * - code: null = promoción automática; no-null = cupón.
 * - channel: Retail | B2B | C2C; null = todos los canales.
 */
final readonly class CreatePromotion implements Command
{
    public function __construct(
        public string $name,
        public string $type,
        public int $value,
        public string $currency = 'BOB',
        public ?string $code = null,
        public int $minSubtotalCents = 0,
        public ?string $channel = null,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
