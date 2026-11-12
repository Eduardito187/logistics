<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Contract;

use DateTimeImmutable;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;

/**
 * Puerta de persistencia del agregado StockTransfer. La idempotencia por event_id se respalda en el
 * índice único de la tabla; `findByEventId` permite resolverla antes de crear el agregado.
 */
interface StockTransferRepository
{
    /**
     * Reserva una identidad nueva (UUID v7); no la genera la base.
     */
    public function nextIdentity(): StockTransferId;

    public function save(StockTransfer $transfer): void;

    public function findById(StockTransferId $id): ?StockTransfer;

    /**
     * Clave de idempotencia: busca el traspaso originado por un event_id de recepción. Null si no hay.
     */
    public function findByEventId(string $eventId): ?StockTransfer;

    /**
     * Traspasos pendientes de empuje a SAP (Pending y Failed): lo que toma el worker de despacho.
     *
     * @return list<StockTransfer>
     */
    public function pending(): array;

    /**
     * Lote acotado de traspasos a (re)empujar por el worker de reconciliación:
     *  - Failed cuyo último intento (última escritura) es anterior a $failedUpdatedBefore (backoff);
     *  - Pending sin tocar desde antes de $pendingUpdatedBefore (estancados); null = no incluir Pending.
     * Ordenados del más viejo al más nuevo.
     *
     * @return list<StockTransfer>
     */
    public function dispatchable(?DateTimeImmutable $pendingUpdatedBefore, DateTimeImmutable $failedUpdatedBefore, int $limit): array;

    /**
     * Traspasos fallidos, para el panel de reconciliación.
     *
     * @return list<StockTransfer>
     */
    public function failed(): array;
}
