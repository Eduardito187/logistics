<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\CreateCategory;

use Commerce\Catalog\Domain\Contract\CategoryRepository;
use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\Slug;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Crea una categoría, la persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<CreateCategory>
 */
final readonly class CreateCategoryHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CreateCategory);

        $category = Category::create(
            $this->categories->nextIdentity(),
            $command->name,
            Slug::of($command->slug),
            $command->parentId !== null ? CategoryId::fromString($command->parentId) : null,
        );

        $this->categories->save($category);

        $this->recordEvents->record($category->releaseEvents(), 'catalog.category');
    }
}
