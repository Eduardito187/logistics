<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Id tipado de una respuesta de encuesta de satisfacción (UUID v7).
 */
final readonly class SurveyId extends AbstractId {}
