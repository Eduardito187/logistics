<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se detectó, en un barrido programado, un conjunto de documentos de flota por vencer o ya vencidos.
 *
 * Es un evento de resumen (digest) del barrido: reúne en un solo hecho todos los documentos que caen
 * dentro de la ventana de alerta a la fecha de referencia, para que el supervisor reciba un aviso
 * consolidado (vía outbox → notificaciones) en lugar de un aviso por documento.
 *
 * El `aggregateId` es sintético y estable por día de referencia (`fleet-document-expiry-<fecha>`): el
 * barrido no tiene un agregado único, pero así los eventos de un mismo día quedan correlacionados.
 */
final readonly class FleetDocumentsExpiryAlerted extends BaseDomainEvent
{
    /**
     * @param list<array{owner_type: string, owner_id: string, owner_label: string, document_type: string, expires_on: string, expired: bool}> $documents
     */
    public function __construct(
        string $aggregateId,
        public string $referenceDate,
        public int $thresholdDays,
        public int $expiredCount,
        public int $expiringCount,
        public array $documents,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.documents_expiry_alerted';
    }

    public function payload(): array
    {
        return [
            'reference_date' => $this->referenceDate,
            'threshold_days' => $this->thresholdDays,
            'expired_count' => $this->expiredCount,
            'expiring_count' => $this->expiringCount,
            'total_count' => $this->expiredCount + $this->expiringCount,
            'documents' => $this->documents,
        ];
    }
}
