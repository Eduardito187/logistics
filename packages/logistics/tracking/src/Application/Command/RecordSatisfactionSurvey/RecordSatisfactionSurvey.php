<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\RecordSatisfactionSurvey;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando: el cliente califica la entrega de su envío desde la página pública de rastreo (K-06).
 *
 * El seguimiento se identifica por el token público (la credencial del enlace), nunca por el id del
 * envío ni datos personales. El motivo es opcional y solo aplica a calificaciones bajas.
 */
final readonly class RecordSatisfactionSurvey implements Command
{
    public function __construct(
        public string $token,
        public int $rating,
        public ?string $comment = null,
        public ?string $reason = null,
    ) {}
}
