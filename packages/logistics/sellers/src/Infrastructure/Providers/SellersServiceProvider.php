<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Sellers\Application\Command\ActivateSeller\ActivateSeller;
use Logistics\Sellers\Application\Command\ActivateSeller\ActivateSellerHandler;
use Logistics\Sellers\Application\Command\RegisterSeller\RegisterSeller;
use Logistics\Sellers\Application\Command\RegisterSeller\RegisterSellerHandler;
use Logistics\Sellers\Application\Command\SetSettlementTerms\SetSettlementTerms;
use Logistics\Sellers\Application\Command\SetSettlementTerms\SetSettlementTermsHandler;
use Logistics\Sellers\Application\Command\SuspendSeller\SuspendSeller;
use Logistics\Sellers\Application\Command\SuspendSeller\SuspendSellerHandler;
use Logistics\Sellers\Application\Command\UpsertSellerSku\UpsertSellerSku;
use Logistics\Sellers\Application\Command\UpsertSellerSku\UpsertSellerSkuHandler;
use Logistics\Sellers\Application\Query\GetSeller\GetSeller;
use Logistics\Sellers\Application\Query\GetSeller\GetSellerHandler;
use Logistics\Sellers\Application\Query\ListSellers\ListSellers;
use Logistics\Sellers\Application\Query\ListSellers\ListSellersHandler;
use Logistics\Sellers\Application\Query\ListSellerSkus\ListSellerSkus;
use Logistics\Sellers\Application\Query\ListSellerSkus\ListSellerSkusHandler;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Repository\EloquentSellerRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Sellers (party de marketplace: alta, términos de liquidación, catálogo).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class SellersServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RegisterSeller::class => RegisterSellerHandler::class,
        ActivateSeller::class => ActivateSellerHandler::class,
        SuspendSeller::class => SuspendSellerHandler::class,
        SetSettlementTerms::class => SetSettlementTermsHandler::class,
        UpsertSellerSku::class => UpsertSellerSkuHandler::class,
        GetSeller::class => GetSellerHandler::class,
        ListSellers::class => ListSellersHandler::class,
        ListSellerSkus::class => ListSellerSkusHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(SellerRepository::class, EloquentSellerRepository::class);
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
