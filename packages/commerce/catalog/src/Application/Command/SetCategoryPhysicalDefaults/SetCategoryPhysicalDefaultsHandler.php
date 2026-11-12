<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\SetCategoryPhysicalDefaults;

use Commerce\Catalog\Domain\Contract\CategoryRepository;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\PhysicalSpec;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define las medidas por defecto de una categoría. Valida rangos en PhysicalSpec y persiste.
 *
 * @implements CommandHandler<SetCategoryPhysicalDefaults>
 */
final readonly class SetCategoryPhysicalDefaultsHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetCategoryPhysicalDefaults);

        $category = $this->categories->findById(CategoryId::fromString($command->categoryId));
        if ($category === null) {
            throw CatalogException::because('La categoría no existe.');
        }

        $category->definePhysicalDefaults(PhysicalSpec::of(
            $command->weightKg,
            $command->lengthCm,
            $command->widthCm,
            $command->heightCm,
        ));

        $this->categories->save($category);
    }
}
