<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del aviso (notificación fire-and-forget) a SAP. Por dentro es un UUID v7.
 */
final readonly class SapNotificationId extends AbstractId {}
