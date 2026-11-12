<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\SetProductPhysicalSpec;

use Commerce\Catalog\Application\Exception\ProductNotFound;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\PhysicalSpec;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define las medidas físicas de un producto existente, buscado por id o por SKU canónico.
 *
 * Valida rangos en el VO PhysicalSpec (CatalogException → 422 en la API), persiste y publica
 * `catalog.product_physical_spec_changed` al outbox en la misma transacción (solo si la ficha cambió).
 * SAP NO es fuente de medidas: a lo sumo se le notifica aguas abajo por el outbox.
 *
 * @implements CommandHandler<SetProductPhysicalSpec>
 */
final readonly class SetProductPhysicalSpecHandler implements CommandHandler
{
    public function __construct(
        private ProductRepository $products,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetProductPhysicalSpec);

        $product = $this->resolve($command);

        $product->definePhysicalSpec(PhysicalSpec::of(
            $command->weightKg,
            $command->lengthCm,
            $command->widthCm,
            $command->heightCm,
        ));

        $this->products->save($product);

        $this->recordEvents->record($product->releaseEvents(), 'catalog.product');
    }

    private function resolve(SetProductPhysicalSpec $command): Product
    {
        if ($command->productId !== null && $command->productId !== '') {
            return $this->products->findById(ProductId::fromString($command->productId))
                ?? throw ProductNotFound::withId($command->productId);
        }

        if ($command->sku !== null && trim($command->sku) !== '') {
            $sku = Sku::of($command->sku);

            return $this->products->findByCanonicalSku($sku)
                ?? throw ProductNotFound::withSku($sku->value);
        }

        throw ProductNotFound::withoutReference();
    }
}
