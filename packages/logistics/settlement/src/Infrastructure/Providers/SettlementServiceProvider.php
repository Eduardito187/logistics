<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Settlement\Application\Command\ConfirmSettlement\ConfirmSettlement;
use Logistics\Settlement\Application\Command\ConfirmSettlement\ConfirmSettlementHandler;
use Logistics\Settlement\Application\Command\DraftSellerSettlement\DraftSellerSettlement;
use Logistics\Settlement\Application\Command\DraftSellerSettlement\DraftSellerSettlementHandler;
use Logistics\Settlement\Application\Command\MarkSettlementPaid\MarkSettlementPaid;
use Logistics\Settlement\Application\Command\MarkSettlementPaid\MarkSettlementPaidHandler;
use Logistics\Settlement\Application\Query\GetSellerStatement\GetSellerStatement;
use Logistics\Settlement\Application\Query\GetSellerStatement\GetSellerStatementHandler;
use Logistics\Settlement\Application\Query\GetSettlement\GetSettlement;
use Logistics\Settlement\Application\Query\GetSettlement\GetSettlementHandler;
use Logistics\Settlement\Application\Query\ListSettlements\ListSettlements;
use Logistics\Settlement\Application\Query\ListSettlements\ListSettlementsHandler;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Logistics\Settlement\Domain\Contract\SellerDispatchReader;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Infrastructure\CrossContext\EloquentSellerDirectory;
use Logistics\Settlement\Infrastructure\CrossContext\EloquentSellerDispatchReader;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Repository\EloquentSellerSettlementRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Settlement (liquidación de sellers de marketplace, Fase 4).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class SettlementServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        DraftSellerSettlement::class => DraftSellerSettlementHandler::class,
        ConfirmSettlement::class => ConfirmSettlementHandler::class,
        MarkSettlementPaid::class => MarkSettlementPaidHandler::class,
        GetSettlement::class => GetSettlementHandler::class,
        ListSettlements::class => ListSettlementsHandler::class,
        GetSellerStatement::class => GetSellerStatementHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(SellerSettlementRepository::class, EloquentSellerSettlementRepository::class);
        // Lecturas cross-context read-only (sin dependencia composer entre contextos).
        $this->app->bind(SellerDispatchReader::class, EloquentSellerDispatchReader::class);
        $this->app->bind(SellerDirectory::class, EloquentSellerDirectory::class);
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
