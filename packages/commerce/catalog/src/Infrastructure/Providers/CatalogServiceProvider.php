<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Providers;

use Commerce\Catalog\Application\Command\AddProductVariant\AddProductVariant;
use Commerce\Catalog\Application\Command\AddProductVariant\AddProductVariantHandler;
use Commerce\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Commerce\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Commerce\Catalog\Application\Command\CreateCategory\CreateCategory;
use Commerce\Catalog\Application\Command\CreateCategory\CreateCategoryHandler;
use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\DraftProduct\DraftProductHandler;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProduct;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProductHandler;
use Commerce\Catalog\Application\Query\GetProduct\GetProduct;
use Commerce\Catalog\Application\Query\GetProduct\GetProductHandler;
use Commerce\Catalog\Application\Query\ListCategories\ListCategories;
use Commerce\Catalog\Application\Query\ListCategories\ListCategoriesHandler;
use Commerce\Catalog\Application\Query\ListProducts\ListProducts;
use Commerce\Catalog\Application\Query\ListProducts\ListProductsHandler;
use Commerce\Catalog\Domain\Contract\CategoryRepository;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Repository\EloquentCategoryRepository;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Repository\EloquentProductRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Catalog (PIM de comercio: productos, categorías, variantes, medios).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle el proyecto. El núcleo SharedServiceProvider va primero.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        DraftProduct::class => DraftProductHandler::class,
        PublishProduct::class => PublishProductHandler::class,
        ArchiveProduct::class => ArchiveProductHandler::class,
        AddProductVariant::class => AddProductVariantHandler::class,
        CreateCategory::class => CreateCategoryHandler::class,
        GetProduct::class => GetProductHandler::class,
        ListProducts::class => ListProductsHandler::class,
        ListCategories::class => ListCategoriesHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
