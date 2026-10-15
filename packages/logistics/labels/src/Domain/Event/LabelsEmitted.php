<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se emitió el lote de etiquetas de un pedido (una por bulto físico).
 *
 * Es el hecho de negocio que viaja al outbox cuando se firman y persisten etiquetas: alimenta
 * auditoría, tracking e integraciones (principio 3 de la arquitectura). `preEmitted` distingue la
 * emisión disparada al planificar (worker, antes de preparar) de la emisión pedida por la app.
 *
 * No lleva el contenido firmado ni el ZPL: solo las identidades (`labelIds`), la clave usada y el
 * conteo. Quien necesite el detalle lo consulta por el pedido; el evento se mantiene liviano.
 */
final readonly class LabelsEmitted extends BaseDomainEvent
{
    /**
     * @param list<string> $labelIds identidades de negocio (campo `id` del payload) de cada etiqueta
     */
    public function __construct(
        string $orderNumber,
        public int $keyId,
        public array $labelIds,
        public bool $preEmitted,
        public int $emittedAt,
    ) {
        parent::__construct($orderNumber);
    }

    public function eventName(): string
    {
        return 'labels.labels_emitted';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'order_number' => $this->aggregateId,
            'key_id' => $this->keyId,
            'label_ids' => $this->labelIds,
            'label_count' => count($this->labelIds),
            'pre_emitted' => $this->preEmitted,
            'emitted_at' => $this->emittedAt,
        ];
    }
}
