<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Model;

use DateInterval;
use DateTimeImmutable;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapDeliveryTrace;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;

/**
 * Aviso fire-and-forget a SAP de un hecho de despacho (principio del dueño: a SAP le importan el stock y
 * los registros; a nuestra plataforma de despacho, no).
 *
 * Tras cada acción relevante se REGISTRA el aviso de nuestro lado, se envía async y se guarda la
 * respuesta cruda de SAP. Nada de lo que haga SAP (responder error, tardar, estar caído) cambia nada
 * de nuestro lado: este agregado es el único que se entera, y solo para dejar el rastro.
 *
 * Clave de idempotencia: `eventId` (el event_id del hecho de dominio). Un mismo hecho produce un solo
 * aviso, respaldado por el índice único de la persistencia. Tope de intentos y backoff los decide el
 * llamador (política de Application); el agregado valida las transiciones y guarda el hecho.
 */
final class SapNotification
{
    /** Tope de bytes de la respuesta cruda de SAP que se guarda (diagnóstico, no un archivo). */
    public const RESPONSE_BODY_LIMIT = 4096;

    /** Tope de caracteres del último error guardado. */
    private const ERROR_LIMIT = 1000;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private readonly SapNotificationId $id,
        private readonly string $eventId,
        private readonly string $eventName,
        private readonly ?string $aggregateId,
        private readonly array $payload,
        private readonly DateTimeImmutable $occurredAt,
        private SapNotificationStatus $status,
        private int $attempts,
        private ?string $lastError,
        private ?int $responseStatus,
        private ?string $responseBody,
        private ?DateTimeImmutable $sentAt,
        private ?DateTimeImmutable $nextAttemptAt,
    ) {}

    /**
     * Registra el aviso de un hecho. Si el envío está habilitado nace Pending (el job ya va en camino;
     * `$graceSeconds` evita que el barrido de reintentos lo re-encole mientras tanto). Si el conector está
     * apagado o sin URL base nace Skipped: queda el rastro, pero no se envía nada.
     *
     * @param array<string, mixed> $payload
     */
    public static function register(
        SapNotificationId $id,
        string $eventId,
        string $eventName,
        ?string $aggregateId,
        array $payload,
        DateTimeImmutable $occurredAt,
        bool $deliveryEnabled,
        DateTimeImmutable $now,
        int $graceSeconds = 120,
    ): self {
        $trimmedEventId = trim($eventId);
        if ($trimmedEventId === '') {
            throw SapConnectorException::because('el event_id del aviso a SAP no puede estar vacío');
        }
        $trimmedEventName = trim($eventName);
        if ($trimmedEventName === '') {
            throw SapConnectorException::because('el nombre del evento del aviso a SAP no puede estar vacío');
        }
        $trimmedAggregate = $aggregateId !== null && trim($aggregateId) !== '' ? trim($aggregateId) : null;

        return new self(
            $id,
            $trimmedEventId,
            $trimmedEventName,
            $trimmedAggregate,
            $payload,
            $occurredAt,
            $deliveryEnabled ? SapNotificationStatus::Pending : SapNotificationStatus::Skipped,
            0,
            null,
            null,
            null,
            null,
            $deliveryEnabled ? self::plusSeconds($now, $graceSeconds) : null,
        );
    }

    /**
     * Reconstituye el aviso desde la persistencia (los datos de la fila ya son válidos). El rastro del
     * envío (error, respuesta, horas) llega agrupado en un solo valor.
     *
     * @param array<string, mixed> $payload
     */
    public static function restore(
        SapNotificationId $id,
        string $eventId,
        string $eventName,
        ?string $aggregateId,
        array $payload,
        DateTimeImmutable $occurredAt,
        SapNotificationStatus $status,
        int $attempts,
        SapDeliveryTrace $trace,
    ): self {
        return new self(
            $id,
            $eventId,
            $eventName,
            $aggregateId,
            $payload,
            $occurredAt,
            $status,
            $attempts,
            $trace->lastError,
            $trace->responseStatus,
            $trace->responseBody,
            $trace->sentAt,
            $trace->nextAttemptAt,
        );
    }

    /**
     * ¿Se puede intentar un envío? Solo si está Pending/Failed y aún quedan intentos.
     */
    public function canAttempt(int $maxAttempts): bool
    {
        return $this->status->isDeliverable() && $this->attempts < max(1, $maxAttempts);
    }

    /**
     * El conector quedó apagado (o sin URL) entre el registro y el envío: se marca Skipped y no se envía.
     */
    public function skip(): void
    {
        if (!$this->status->isDeliverable()) {
            throw SapConnectorException::because("transición inválida a skipped desde {$this->status->value}");
        }

        $this->status = SapNotificationStatus::Skipped;
        $this->nextAttemptAt = null;
    }

    /**
     * SAP aceptó el aviso (2xx): queda Sent con su respuesta cruda (truncada) y la hora de envío.
     */
    public function recordSuccess(SapResponse $response, DateTimeImmutable $now): void
    {
        if (!$this->status->isDeliverable()) {
            throw SapConnectorException::because("transición inválida a sent desde {$this->status->value}");
        }

        $this->attempts++;
        $this->status = SapNotificationStatus::Sent;
        $this->lastError = null;
        $this->responseStatus = $response->status;
        $this->responseBody = $response->truncatedBody(self::RESPONSE_BODY_LIMIT);
        $this->sentAt = $now;
        $this->nextAttemptAt = null;
    }

    /**
     * Un intento falló (red, breaker abierto o respuesta no 2xx). Suma el intento, guarda el motivo y la
     * respuesta (si la hubo) y programa el próximo intento con backoff; si se agotaron los intentos queda
     * Failed sin próximo intento (se deja de insistir: nada de dead-letter ni acción manual obligatoria).
     *
     * @param array<int, int> $backoffMinutes Minutos de espera por intento ya realizado (1 → 1min, ...).
     */
    public function recordFailure(
        string $error,
        ?SapResponse $response,
        DateTimeImmutable $now,
        int $maxAttempts,
        array $backoffMinutes,
    ): void {
        if (!$this->status->isDeliverable()) {
            throw SapConnectorException::because("transición inválida a failed desde {$this->status->value}");
        }

        $this->attempts++;
        $this->status = SapNotificationStatus::Failed;
        $trimmed = trim($error);
        $this->lastError = mb_substr($trimmed !== '' ? $trimmed : 'error desconocido', 0, self::ERROR_LIMIT);
        $this->responseStatus = $response?->status;
        $this->responseBody = $response?->truncatedBody(self::RESPONSE_BODY_LIMIT);

        $this->nextAttemptAt = $this->attempts < max(1, $maxAttempts)
            ? self::plusSeconds($now, 60 * max(0, $backoffMinutes[$this->attempts] ?? 5))
            : null;
    }

    /**
     * Corre el próximo intento (lease): lo usa el barrido de reintentos al re-encolar el aviso, para que
     * la siguiente corrida no lo vuelva a encolar mientras el job está en la cola.
     */
    public function deferUntil(DateTimeImmutable $at): void
    {
        if ($this->status->isDeliverable()) {
            $this->nextAttemptAt = $at;
        }
    }

    /**
     * Reintento operativo (acción del panel): vuelve a Pending con los intentos en cero. Solo sobre avisos
     * no enviados (Failed/Skipped/Pending); reenviar uno ya aceptado por SAP se rechaza.
     */
    public function resetForRetry(DateTimeImmutable $now, int $graceSeconds = 120): void
    {
        if ($this->status === SapNotificationStatus::Sent) {
            throw SapConnectorException::because('no se puede reintentar un aviso ya enviado a SAP');
        }

        $this->status = SapNotificationStatus::Pending;
        $this->attempts = 0;
        $this->lastError = null;
        $this->nextAttemptAt = self::plusSeconds($now, $graceSeconds);
    }

    public function id(): SapNotificationId
    {
        return $this->id;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function aggregateId(): ?string
    {
        return $this->aggregateId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function status(): SapNotificationStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Rastro del envío: último error, respuesta cruda de SAP, hora de aceptación y próximo intento.
     */
    public function deliveryTrace(): SapDeliveryTrace
    {
        return new SapDeliveryTrace(
            $this->lastError,
            $this->responseStatus,
            $this->responseBody,
            $this->sentAt,
            $this->nextAttemptAt,
        );
    }

    private static function plusSeconds(DateTimeImmutable $at, int $seconds): DateTimeImmutable
    {
        return $at->add(new DateInterval('PT'.max(0, $seconds).'S'));
    }
}
