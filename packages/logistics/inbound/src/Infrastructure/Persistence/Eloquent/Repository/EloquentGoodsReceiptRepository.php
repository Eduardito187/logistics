<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Inbound\Domain\Contract\GoodsReceiptRepository;
use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Mapper\GoodsReceiptMapper;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\GoodsReceiptRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de recepciones. `save` es un upsert por id; las líneas se
 * reemplazan en bloque. Las líneas llevan su propio UUID v7.
 */
final readonly class EloquentGoodsReceiptRepository implements GoodsReceiptRepository
{
    public function __construct(
        private GoodsReceiptMapper $mapper = new GoodsReceiptMapper,
    ) {}

    public function nextIdentity(): GoodsReceiptId
    {
        return GoodsReceiptId::generate();
    }

    public function save(GoodsReceipt $receipt): void
    {
        $row = $this->mapper->toRow($receipt);
        $record = GoodsReceiptRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        $record->lines()->delete();
        foreach ($this->mapper->toLineRows($receipt) as $line) {
            $record->lines()->create([
                'id' => Uuid::generate()->value,
                'sku' => $line['sku'],
                'accepted_quantity' => $line['accepted_quantity'],
                'rejected_quantity' => $line['rejected_quantity'],
                'damaged_quantity' => $line['damaged_quantity'],
                'qc_outcome' => $line['qc_outcome'],
            ]);
        }
    }

    public function findById(GoodsReceiptId $id): ?GoodsReceipt
    {
        $record = GoodsReceiptRecord::query()->with('lines')->find($id->toString());

        return $record instanceof GoodsReceiptRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByAsnId(AsnId $asnId): ?GoodsReceipt
    {
        $record = GoodsReceiptRecord::query()->with('lines')->where('asn_id', $asnId->toString())->first();

        return $record instanceof GoodsReceiptRecord ? $this->mapper->toDomain($record) : null;
    }
}
