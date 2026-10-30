<?php

declare(strict_types=1);

namespace Commerce\Search\Infrastructure\Providers;

use Commerce\Search\Application\Contract\ProductSearchReader;
use Commerce\Search\Application\Query\ListFacets\ListFacets;
use Commerce\Search\Application\Query\ListFacets\ListFacetsHandler;
use Commerce\Search\Application\Query\SearchProducts\SearchProducts;
use Commerce\Search\Application\Query\SearchProducts\SearchProductsHandler;
use Commerce\Search\Infrastructure\Console\ReindexProductSearchCommand;
use Commerce\Search\Infrastructure\Read\DatabaseProductSearchReader;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Search (búsqueda y navegación de catálogo, read-side).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle el proyecto. El núcleo SharedServiceProvider va primero.
 */
final class SearchServiceProvider extends ServiceProvider
{
    /**
     * Pares consulta → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        SearchProducts::class => SearchProductsHandler::class,
        ListFacets::class => ListFacetsHandler::class,
    ];

    public function register(): void
    {
        // El puerto de lectura se resuelve al adaptador que consulta la proyección `commerce_product_search`
        // (con fallback ILIKE sobre el catálogo mientras la proyección no esté indexada).
        $this->app->bind(ProductSearchReader::class, DatabaseProductSearchReader::class);
    }

    public function boot(): void
    {
        // Migración de la proyección de búsqueda `commerce_product_search` (tabla propia + índice GIN).
        // Aditiva e idempotente; la pobla el comando `commerce:search:reindex` / el dispatcher de outbox.
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Comando de mantenimiento del read-side: reconstruye la proyección desde el catálogo.
        if ($this->app->runningInConsole()) {
            $this->commands([ReindexProductSearchCommand::class]);
        }

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
