<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\ArchiveProduct;

use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Archiva un producto existente. Idempotente.
 *
 * @implements CommandHandler<ArchiveProduct>
 */
final readonly class ArchiveProductHandler implements CommandHandler
{
    public function __construct(
        private ProductRepository $products,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ArchiveProduct);

        $product = $this->products->findById(ProductId::fromString($command->productId));
        if ($product === null) {
            throw CatalogException::because('El producto a archivar no existe.');
        }

        $product->archive();

        $this->products->save($product);

        $this->recordEvents->record($product->releaseEvents(), 'catalog.product');
    }
}
