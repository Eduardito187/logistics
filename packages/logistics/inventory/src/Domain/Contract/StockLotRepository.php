<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Contract;

use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\StockMovement;

/**
 * Puerto de persistencia de lotes de stock (lado de ESCRITURA). La implementación vive en Infrastructure.
 *
 * Las lecturas de gran volumen (on-hand agregado, listados, ATP) NO pasan por acá: usan el
 * StockQueryStore, que agrega en SQL para escalar a millones de lotes (CQRS).
 */
interface StockLotRepository
{
    public function nextIdentity(): LotId;

    /**
     * Upsert del lote por id.
     */
    public function save(StockLot $lot): void;

    public function findById(LotId $id): ?StockLot;

    /**
     * Registra un asiento en el ledger de movimientos (append-only).
     */
    public function appendMovement(StockMovement $movement): void;
}
