<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una notificación. Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class NotificationId extends AbstractId {}
