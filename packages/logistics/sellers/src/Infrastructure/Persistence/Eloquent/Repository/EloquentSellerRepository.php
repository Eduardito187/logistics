<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Mapper\SellerMapper;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSettlementTermsRecord;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSkuRecord;

/**
 * Implementación Eloquent del repositorio de sellers. `save` es un upsert por id del seller y de sus
 * términos de liquidación; el catálogo de SKUs se persiste fila a fila con `saveSku`.
 */
final readonly class EloquentSellerRepository implements SellerRepository
{
    public function __construct(
        private SellerMapper $mapper = new SellerMapper,
    ) {}

    public function nextIdentity(): SellerId
    {
        return SellerId::generate();
    }

    public function save(Seller $seller): void
    {
        $row = $this->mapper->toRow($seller);
        SellerRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        $termsRow = $this->mapper->toTermsRow($seller);
        if ($termsRow !== null) {
            // No se usa updateOrCreate con el id en los valores: regeneraría la PK en cada update. Se
            // conserva el id existente y solo se crea uno nuevo (UUID v7) cuando no había términos.
            $existing = SellerSettlementTermsRecord::query()->where('seller_id', $row['id'])->first();
            if ($existing instanceof SellerSettlementTermsRecord) {
                $existing->update($termsRow);
            } else {
                SellerSettlementTermsRecord::query()->create(
                    ['id' => SellerId::generate()->toString(), 'seller_id' => $row['id']] + $termsRow,
                );
            }
        }
    }

    public function findById(SellerId $id): ?Seller
    {
        $record = SellerRecord::query()->with('settlementTerms')->find($id->toString());

        return $record instanceof SellerRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(SellerRecord::query()
            ->with('settlementTerms')
            ->orderBy('legal_name')
            ->get()
            ->map(fn (SellerRecord $record): Seller => $this->mapper->toDomain($record))
            ->all());
    }

    public function saveSku(SellerId $sellerId, SellerSku $sku): void
    {
        // Idempotente por clave natural (seller_id, seller_sku) sin regenerar la PK en cada update.
        $existing = SellerSkuRecord::query()
            ->where('seller_id', $sellerId->toString())
            ->where('seller_sku', $sku->sku)
            ->first();

        if ($existing instanceof SellerSkuRecord) {
            $existing->update(['sap_material' => $sku->sapMaterial]);

            return;
        }

        SellerSkuRecord::query()->create([
            'id' => SellerId::generate()->toString(),
            'seller_id' => $sellerId->toString(),
            'seller_sku' => $sku->sku,
            'sap_material' => $sku->sapMaterial,
        ]);
    }

    public function listSkus(SellerId $sellerId): array
    {
        return array_values(SellerSkuRecord::query()
            ->where('seller_id', $sellerId->toString())
            ->orderBy('seller_sku')
            ->get()
            ->map(static fn (SellerSkuRecord $record): SellerSku => SellerSku::of(
                $record->seller_sku,
                $record->sap_material,
            ))
            ->all());
    }
}
