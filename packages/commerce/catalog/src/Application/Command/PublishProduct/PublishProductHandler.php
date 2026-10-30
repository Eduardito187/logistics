<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\PublishProduct;

use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Publica un producto existente. Idempotente (el agregado no re-graba si ya está publicado).
 *
 * @implements CommandHandler<PublishProduct>
 */
final readonly class PublishProductHandler implements CommandHandler
{
    public function __construct(
        private ProductRepository $products,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof PublishProduct);

        $product = $this->products->findById(ProductId::fromString($command->productId));
        if ($product === null) {
            throw CatalogException::because('El producto a publicar no existe.');
        }

        $product->publish();

        $this->products->save($product);

        $this->recordEvents->record($product->releaseEvents(), 'catalog.product');
    }
}
