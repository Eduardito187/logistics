<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain;

use DateTimeImmutable;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Mensaje saliente del patrón Transactional Outbox.
 *
 * Se persiste en la MISMA transacción que el cambio de negocio que lo produjo (ver ADR 002) y se
 * despacha después, de forma asíncrona, idempotente y con reintentos. El eventId es la clave de
 * idempotencia: el store garantiza que un mismo evento no se encola dos veces.
 *
 * Entidad mutable: su estado cambia con el ciclo de vida (despachado, fallido, descartado). La
 * política de backoff y de descarte NO vive acá: el llamador (Application) calcula el próximo intento
 * y decide cuándo descartar; el dominio solo valida que la transición sea legal y guarda el hecho.
 */
final class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private readonly OutboxMessageId $id,
        private readonly Uuid $eventId,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly string $eventName,
        private readonly array $payload,
        private OutboxStatus $status,
        private int $attempts,
        private DateTimeImmutable $availableAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $dispatchedAt,
        private ?string $lastError,
    ) {}

    /**
     * Crea un mensaje Pending a partir de un evento de dominio recién liberado por el agregado.
     *
     * El mensaje queda disponible de inmediato (availableAt = now): el relay lo tomará en su próxima
     * corrida. El aggregateType lo aporta el contexto emisor (p. ej. "network.node").
     */
    public static function fromDomainEvent(DomainEvent $event, string $aggregateType, DateTimeImmutable $now): self
    {
        return new self(
            id: OutboxMessageId::generate(),
            eventId: $event->eventId(),
            aggregateType: $aggregateType,
            aggregateId: $event->aggregateId(),
            eventName: $event->eventName(),
            payload: $event->payload(),
            status: OutboxStatus::Pending,
            attempts: 0,
            availableAt: $now,
            createdAt: $now,
            dispatchedAt: null,
            lastError: null,
        );
    }

    /**
     * Reconstituye un mensaje desde su forma persistida (lo usa el mapper de infraestructura).
     *
     * @param array<string, mixed> $payload
     */
    public static function restore(
        OutboxMessageId $id,
        Uuid $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventName,
        array $payload,
        OutboxStatus $status,
        int $attempts,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $dispatchedAt,
        ?string $lastError,
    ): self {
        return new self(
            $id,
            $eventId,
            $aggregateType,
            $aggregateId,
            $eventName,
            $payload,
            $status,
            $attempts,
            $availableAt,
            $createdAt,
            $dispatchedAt,
            $lastError,
        );
    }

    /**
     * Marca el mensaje como despachado con éxito. Transición terminal.
     *
     * @param DateTimeImmutable|null $at Momento del despacho; por defecto, ahora.
     */
    public function markDispatched(?DateTimeImmutable $at = null): void
    {
        $this->guardDispatchable(OutboxStatus::Dispatched);

        $this->status = OutboxStatus::Dispatched;
        $this->dispatchedAt = $at ?? new DateTimeImmutable();
        $this->lastError = null;
    }

    /**
     * Registra un intento fallido y programa el próximo. Suma un intento y reprograma availableAt.
     *
     * El cálculo de $nextAttemptAt (backoff exponencial) es responsabilidad del llamador.
     */
    public function markFailed(string $error, DateTimeImmutable $nextAttemptAt): void
    {
        $this->guardDispatchable(OutboxStatus::Failed);

        $this->status = OutboxStatus::Failed;
        $this->attempts++;
        $this->availableAt = $nextAttemptAt;
        $this->lastError = $error;
    }

    /**
     * Descarta el mensaje tras agotar los reintentos: queda a la espera de intervención manual.
     * Transición terminal.
     */
    public function markDeadLettered(string $error): void
    {
        $this->guardDispatchable(OutboxStatus::DeadLettered);

        $this->status = OutboxStatus::DeadLettered;
        $this->attempts++;
        $this->lastError = $error;
    }

    public function id(): OutboxMessageId
    {
        return $this->id;
    }

    public function eventId(): Uuid
    {
        return $this->eventId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): OutboxStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function availableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function dispatchedAt(): ?DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Garantiza que el mensaje esté en un estado despachable antes de transicionarlo.
     */
    private function guardDispatchable(OutboxStatus $to): void
    {
        if ($this->status->isTerminal()) {
            throw OutboxException::cannotTransition($this->status, $to->value);
        }
    }
}
