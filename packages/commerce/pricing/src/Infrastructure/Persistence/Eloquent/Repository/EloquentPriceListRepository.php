<?php

declare(strict_types=1);

namespace Commerce\Pricing\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\Model\PriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\PriceListStatus;
use Commerce\Pricing\Domain\ValueObject\Sku;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Mapper\PriceListMapper;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListItemRecord;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListRecord;

/**
 * Implementación Eloquent del repositorio de listas de precios. `save` es un upsert por id de la cabecera
 * de la lista; los precios (ítems) se persisten fila a fila con `setPrice`.
 */
final readonly class EloquentPriceListRepository implements PriceListRepository
{
    public function __construct(
        private PriceListMapper $mapper = new PriceListMapper,
    ) {}

    public function nextIdentity(): PriceListId
    {
        return PriceListId::generate();
    }

    public function save(PriceList $list): void
    {
        $row = $this->mapper->toRow($list);
        PriceListRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(PriceListId $id): ?PriceList
    {
        $record = PriceListRecord::query()->find($id->toString());

        return $record instanceof PriceListRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(PriceListRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (PriceListRecord $record): PriceList => $this->mapper->toDomain($record))
            ->all());
    }

    public function findActiveByChannel(Channel $channel): ?PriceList
    {
        $record = PriceListRecord::query()
            ->where('channel', $channel->value)
            ->where('status', PriceListStatus::Active->value)
            ->orderBy('name')
            ->first();

        return $record instanceof PriceListRecord ? $this->mapper->toDomain($record) : null;
    }

    public function setPrice(PriceListId $listId, Sku $sku, Money $grossAmount): void
    {
        // Idempotente por clave natural (price_list_id, sku) sin regenerar la PK en cada update.
        $existing = PriceListItemRecord::query()
            ->where('price_list_id', $listId->toString())
            ->where('sku', $sku->value)
            ->first();

        if ($existing instanceof PriceListItemRecord) {
            $existing->update([
                'gross_amount_cents' => $grossAmount->cents,
                'currency' => $grossAmount->currency,
            ]);

            return;
        }

        PriceListItemRecord::query()->create([
            'id' => PriceListId::generate()->toString(),
            'price_list_id' => $listId->toString(),
            'sku' => $sku->value,
            'gross_amount_cents' => $grossAmount->cents,
            'currency' => $grossAmount->currency,
        ]);
    }

    public function findPrice(PriceListId $listId, Sku $sku): ?Money
    {
        $record = PriceListItemRecord::query()
            ->where('price_list_id', $listId->toString())
            ->where('sku', $sku->value)
            ->first();

        return $record instanceof PriceListItemRecord
            ? Money::of($record->gross_amount_cents, $record->currency)
            : null;
    }

    public function listPrices(PriceListId $listId): array
    {
        return array_values(PriceListItemRecord::query()
            ->where('price_list_id', $listId->toString())
            ->orderBy('sku')
            ->get()
            ->map(static fn (PriceListItemRecord $record): array => [
                'sku' => $record->sku,
                'gross_amount' => Money::of($record->gross_amount_cents, $record->currency),
            ])
            ->all());
    }
}
