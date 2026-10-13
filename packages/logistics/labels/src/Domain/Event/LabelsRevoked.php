<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se revocaron las etiquetas vigentes de un pedido (típicamente por reimpresión: ADR 004).
 *
 * Una etiqueta revocada NO se borra: queda en la base marcada con `revoked_at` para auditoría, pero
 * deja de ser válida. La app y la ejecución deben rechazar un QR cuya etiqueta fue revocada. El hecho
 * viaja al outbox para que los consumidores (tracking, ejecución) invaliden lo ya distribuido.
 */
final readonly class LabelsRevoked extends BaseDomainEvent
{
    /**
     * @param list<string> $labelIds identidades de negocio revocadas
     */
    public function __construct(
        string $orderNumber,
        public array $labelIds,
        public string $reason,
        public int $revokedAt,
    ) {
        parent::__construct($orderNumber);
    }

    public function eventName(): string
    {
        return 'labels.labels_revoked';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'order_number' => $this->aggregateId,
            'label_ids' => $this->labelIds,
            'label_count' => count($this->labelIds),
            'reason' => $this->reason,
            'revoked_at' => $this->revokedAt,
        ];
    }
}
