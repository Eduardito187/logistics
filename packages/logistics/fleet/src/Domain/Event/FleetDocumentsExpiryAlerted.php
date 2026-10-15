<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Se detectó, en un barrido programado, un conjunto de documentos de flota por vencer o ya vencidos.
 *
 * Es un evento de resumen (digest) del barrido: reúne en un solo hecho todos los documentos que caen
 * dentro de la ventana de alerta a la fecha de referencia, clasificados por umbral (vencido, ≤7 días,
 * ≤30 días), para que el supervisor reciba un aviso consolidado (vía outbox → notificaciones) en lugar
 * de un aviso por documento.
 *
 * El `aggregateId` es sintético y estable por día de referencia (`fleet-document-expiry-<fecha>`): el
 * barrido no tiene un agregado único, pero así los eventos de un mismo día quedan correlacionados.
 *
 * IDEMPOTENCIA: el `eventId` se deriva de forma determinista del `aggregateId` (que incluye el día), de
 * modo que dos corridas del mismo día producen el MISMO eventId y el outbox —idempotente por eventId—
 * no vuelve a encolar la alerta. No se re-alerta lo mismo el mismo día.
 */
final readonly class FleetDocumentsExpiryAlerted extends BaseDomainEvent
{
    /**
     * @param list<array{owner_type: string, owner_id: string, owner_label: string, document_type: string, expires_on: string, expired: bool, threshold?: string}> $documents
     */
    public function __construct(
        string $aggregateId,
        public string $referenceDate,
        public int $thresholdDays,
        public int $expiredCount,
        public int $expiringCount,
        public array $documents,
        public int $dueSoonCount = 0,
        public int $upcomingCount = 0,
    ) {
        parent::__construct($aggregateId, self::deterministicId($aggregateId));
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
            'due_soon_count' => $this->dueSoonCount,
            'upcoming_count' => $this->upcomingCount,
            'total_count' => $this->expiredCount + $this->expiringCount,
            'documents' => $this->documents,
        ];
    }

    /**
     * Deriva un UUID estable (name-based, estilo v5) a partir de la semilla del barrido. El mismo día
     * de referencia siempre produce el mismo id, lo que da la idempotencia a través del outbox.
     */
    private static function deterministicId(string $seed): Uuid
    {
        $hash = sha1('fleet.documents_expiry_alerted|'.$seed);

        $timeHiAndVersion = (((int) hexdec(substr($hash, 12, 4))) & 0x0FFF) | 0x5000;
        $clockSeq = (((int) hexdec(substr($hash, 16, 4))) & 0x3FFF) | 0x8000;

        return Uuid::fromString(sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHiAndVersion,
            $clockSeq,
            substr($hash, 20, 12),
        ));
    }
}
