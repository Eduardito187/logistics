<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Id tipado de una suscripción Web Push de la página pública de rastreo (UUID v7).
 */
final readonly class PushSubscriptionId extends AbstractId {}
