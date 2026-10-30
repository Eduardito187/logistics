<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\DraftProduct;

use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Construye el producto en borrador, lo persiste y publica sus eventos al outbox (misma transacción que
 * abre el decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<DraftProduct>
 */
final readonly class DraftProductHandler implements CommandHandler
{
    public function __construct(
        private ProductRepository $products,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DraftProduct);

        $product = Product::draft(
            $this->products->nextIdentity(),
            $command->sellerId !== null ? Uuid::fromString($command->sellerId) : null,
            $command->name,
            Sku::of($command->canonicalSku),
            $command->description,
            $command->categoryId !== null ? CategoryId::fromString($command->categoryId) : null,
        );

        $this->products->save($product);

        $this->recordEvents->record($product->releaseEvents(), 'catalog.product');
    }
}
