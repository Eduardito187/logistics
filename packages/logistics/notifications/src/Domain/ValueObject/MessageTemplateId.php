<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una plantilla de mensaje. Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class MessageTemplateId extends AbstractId {}
