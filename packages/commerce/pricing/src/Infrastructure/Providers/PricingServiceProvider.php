<?php

declare(strict_types=1);

namespace Commerce\Pricing\Infrastructure\Providers;

use Commerce\Pricing\Application\Command\ActivatePriceList\ActivatePriceList;
use Commerce\Pricing\Application\Command\ActivatePriceList\ActivatePriceListHandler;
use Commerce\Pricing\Application\Command\ArchivePriceList\ArchivePriceList;
use Commerce\Pricing\Application\Command\ArchivePriceList\ArchivePriceListHandler;
use Commerce\Pricing\Application\Command\CreatePriceList\CreatePriceList;
use Commerce\Pricing\Application\Command\CreatePriceList\CreatePriceListHandler;
use Commerce\Pricing\Application\Command\SetPrice\SetPrice;
use Commerce\Pricing\Application\Command\SetPrice\SetPriceHandler;
use Commerce\Pricing\Application\Query\GetPrice\GetPrice;
use Commerce\Pricing\Application\Query\GetPrice\GetPriceHandler;
use Commerce\Pricing\Application\Query\ListPriceLists\ListPriceLists;
use Commerce\Pricing\Application\Query\ListPriceLists\ListPriceListsHandler;
use Commerce\Pricing\Application\Query\ListPrices\ListPrices;
use Commerce\Pricing\Application\Query\ListPrices\ListPricesHandler;
use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Repository\EloquentPriceListRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Pricing (precios e impuestos del frente comercial, ADR 018).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class PricingServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        CreatePriceList::class => CreatePriceListHandler::class,
        ActivatePriceList::class => ActivatePriceListHandler::class,
        ArchivePriceList::class => ArchivePriceListHandler::class,
        SetPrice::class => SetPriceHandler::class,
        GetPrice::class => GetPriceHandler::class,
        ListPriceLists::class => ListPriceListsHandler::class,
        ListPrices::class => ListPricesHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(PriceListRepository::class, EloquentPriceListRepository::class);
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
