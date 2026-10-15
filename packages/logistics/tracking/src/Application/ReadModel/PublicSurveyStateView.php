<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Estado de la encuesta de satisfacción para un token, visto desde la página pública (sin PII).
 *
 * Solo dice si el envío es elegible para calificar (está entregado) y si ya se envió una respuesta.
 * Con esto la página decide si mostrar el formulario de estrellas o el agradecimiento, y el endpoint
 * de registro decide el código HTTP sin tener que parsear mensajes del dominio.
 */
final readonly class PublicSurveyStateView
{
    public function __construct(
        public bool $eligible,
        public bool $alreadySubmitted,
    ) {}

    /**
     * ¿Debe mostrarse el formulario? Solo si es elegible y aún no respondió.
     */
    public function canSubmit(): bool
    {
        return $this->eligible && !$this->alreadySubmitted;
    }

    /**
     * @return array{eligible: bool, already_submitted: bool, can_submit: bool}
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'already_submitted' => $this->alreadySubmitted,
            'can_submit' => $this->canSubmit(),
        ];
    }
}
