<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicSurveyState;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el estado de la encuesta de satisfacción de un token público (elegibilidad + si ya respondió).
 */
final readonly class GetPublicSurveyState implements Query
{
    public function __construct(
        public string $token,
    ) {}
}
