<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Hecho de dominio: el cliente calificó la entrega de su envío desde la página pública de rastreo.
 *
 * Lo libera el agregado SatisfactionSurvey al grabarse y viaja por el outbox para que consumidores
 * internos lo procesen (métricas de NPS, alertas de insatisfacción, etc.). No lleva datos personales:
 * el envío se referencia por su id, no por el cliente. El comentario libre del cliente va en la carga
 * porque es, precisamente, el contenido de la encuesta destinado a operaciones (consumo interno).
 */
final readonly class SatisfactionSurveyRecorded extends BaseDomainEvent
{
    public function __construct(
        string $surveyId,
        public string $shipmentId,
        public int $rating,
        public ?string $comment,
        public ?string $reason,
    ) {
        parent::__construct($surveyId);
    }

    public function eventName(): string
    {
        return 'tracking.satisfaction_survey_recorded';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reason' => $this->reason,
        ];
    }
}
