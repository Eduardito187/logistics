<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Model;

use Logistics\Notifications\Domain\Event\NotificationQueued;
use Logistics\Notifications\Domain\Event\NotificationSent;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\FcmTopic;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz del contexto: una notificación a enviar al cliente de un envío, que recorre una
 * cascada de canales (ADR 006). Apunta siempre a un canal actual de la política; si no hay apertura,
 * escala al siguiente, y al agotarse la cascada queda fallida.
 *
 * El envío real es asíncrono (por outbox): el agregado solo registra el estado y los hechos; no habla
 * con FCM, email ni SMS.
 */
final class Notification extends AggregateRoot
{
    /**
     * @param array<string, int> $attempts Intentos por canal, keyed por Channel->value.
     */
    private function __construct(
        private readonly NotificationId $id,
        private readonly string $shipmentId,
        private readonly MessageType $messageType,
        private readonly CascadePolicy $policy,
        private NotificationStatus $status,
        private Channel $currentChannel,
        private array $attempts,
    ) {}

    /**
     * Encola una notificación: arranca en Queued apuntando al primer canal de la política y graba el
     * hecho de encolado (que el outbox usará para despachar el primer envío).
     */
    public static function queue(
        NotificationId $id,
        string $shipmentId,
        MessageType $messageType,
        CascadePolicy $policy,
    ): self {
        $trimmedShipment = trim($shipmentId);
        if ($trimmedShipment === '') {
            throw NotificationsException::because('una notificación necesita un id de envío');
        }

        $notification = new self(
            $id,
            $trimmedShipment,
            $messageType,
            $policy,
            NotificationStatus::Queued,
            $policy->first(),
            [],
        );

        $notification->recordEvent(new NotificationQueued(
            $id->toString(),
            $trimmedShipment,
            $messageType->value,
            $policy->first()->value,
            FcmTopic::forShipment($trimmedShipment)->value,
            array_map(static fn (Channel $c): string => $c->value, $policy->channels),
        ));

        return $notification;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param array<string, int> $attempts
     */
    public static function reconstitute(
        NotificationId $id,
        string $shipmentId,
        MessageType $messageType,
        CascadePolicy $policy,
        NotificationStatus $status,
        Channel $currentChannel,
        array $attempts,
    ): self {
        return new self($id, $shipmentId, $messageType, $policy, $status, $currentChannel, $attempts);
    }

    /**
     * Marca la notificación como enviada por un canal. Cuenta el intento y cierra la cascada: ya no
     * se escala.
     */
    public function markSentVia(Channel $channel): void
    {
        if ($this->status === NotificationStatus::Suppressed) {
            throw NotificationsException::because('no se puede enviar una notificación suprimida');
        }
        if (!$this->policy->contains($channel)) {
            throw NotificationsException::because("el canal {$channel->value} no pertenece a la política");
        }

        $this->countAttempt($channel);
        $this->currentChannel = $channel;
        $this->status = NotificationStatus::Sent;

        $this->recordEvent(new NotificationSent(
            $this->id->toString(),
            $this->shipmentId,
            $this->messageType->value,
            $channel->value,
        ));
    }

    /**
     * Escala al siguiente canal de la política (no hubo apertura en el actual). Cuenta el intento del
     * canal que se abandona. Si la cascada se agota, la notificación queda fallida.
     */
    public function escalate(): void
    {
        if ($this->status !== NotificationStatus::Queued) {
            throw NotificationsException::because("solo se escala una notificación encolada, no una {$this->status->value}");
        }

        $this->countAttempt($this->currentChannel);

        $next = $this->policy->next($this->currentChannel);
        if ($next === null) {
            $this->status = NotificationStatus::Failed;

            return;
        }

        $this->currentChannel = $next;
    }

    /**
     * Suprime la notificación: no se enviará (por tope de SMS, reglas o porque dejó de ser relevante).
     */
    public function suppress(): void
    {
        if ($this->status === NotificationStatus::Sent) {
            throw NotificationsException::because('no se puede suprimir una notificación ya enviada');
        }

        $this->status = NotificationStatus::Suppressed;
    }

    public function id(): NotificationId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function messageType(): MessageType
    {
        return $this->messageType;
    }

    public function policy(): CascadePolicy
    {
        return $this->policy;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function currentChannel(): Channel
    {
        return $this->currentChannel;
    }

    /**
     * Intentos por canal, keyed por Channel->value.
     *
     * @return array<string, int>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * Topic FCM del envío (canal primario y web push).
     */
    public function fcmTopic(): FcmTopic
    {
        return FcmTopic::forShipment($this->shipmentId);
    }

    private function countAttempt(Channel $channel): void
    {
        $this->attempts[$channel->value] = ($this->attempts[$channel->value] ?? 0) + 1;
    }
}
