<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Model;

use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Tracking\Domain\Event\SatisfactionSurveyRecorded;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\Rating;
use Logistics\Tracking\Domain\ValueObject\SurveyId;
use Logistics\Tracking\Domain\ValueObject\SurveyReason;

/**
 * Agregado raíz de la encuesta de satisfacción post-entrega (K-06).
 *
 * El cliente califica la entrega (1-5) desde la página pública de rastreo, con un comentario opcional y
 * un motivo opcional cuando la calificación es baja. Referencia al envío por id string (shipmentId),
 * sin importar el agregado de otro contexto ni guardar datos personales: el comentario es texto que el
 * propio cliente escribe sobre SU entrega, destinado a operaciones.
 *
 * REGLA DE IDEMPOTENCIA (decisión documentada): UNA sola respuesta por envío. Un segundo intento se
 * RECHAZA (no se reemplaza la respuesta original); la unicidad la respalda el índice único por
 * shipment_id y la verifica el handler antes de grabar. Así una respuesta ya enviada queda inmutable.
 */
final class SatisfactionSurvey extends AggregateRoot
{
    /** Longitud máxima del comentario libre del cliente. */
    private const int MAX_COMMENT_LENGTH = 1000;

    private function __construct(
        private readonly SurveyId $id,
        private readonly string $shipmentId,
        private readonly string $token,
        private readonly Rating $rating,
        private readonly ?string $comment,
        private readonly ?SurveyReason $reason,
        private readonly DateTimeImmutable $submittedAt,
    ) {}

    /**
     * Graba una respuesta nueva: normaliza el comentario, deriva el motivo y libera el hecho de dominio.
     */
    public static function record(
        SurveyId $id,
        string $shipmentId,
        string $token,
        Rating $rating,
        ?string $comment,
        ?SurveyReason $reason,
        DateTimeImmutable $submittedAt,
    ): self {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('el id del envío no puede estar vacío');
        }

        $comment = self::normalizeComment($comment);

        $survey = new self($id, $shipmentId, $token, $rating, $comment, $reason, $submittedAt);

        $survey->recordEvent(new SatisfactionSurveyRecorded(
            $id->toString(),
            $shipmentId,
            $rating->value,
            $comment,
            $reason?->value,
        ));

        return $survey;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        SurveyId $id,
        string $shipmentId,
        string $token,
        Rating $rating,
        ?string $comment,
        ?SurveyReason $reason,
        DateTimeImmutable $submittedAt,
    ): self {
        return new self($id, $shipmentId, $token, $rating, $comment, $reason, $submittedAt);
    }

    /**
     * Normaliza el comentario: recorta espacios, deja null si queda vacío y trunca al máximo permitido.
     */
    private static function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $comment = trim($comment);
        if ($comment === '') {
            return null;
        }

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            $comment = mb_substr($comment, 0, self::MAX_COMMENT_LENGTH);
        }

        return $comment;
    }

    public function id(): SurveyId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function rating(): Rating
    {
        return $this->rating;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function reason(): ?SurveyReason
    {
        return $this->reason;
    }

    public function submittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
