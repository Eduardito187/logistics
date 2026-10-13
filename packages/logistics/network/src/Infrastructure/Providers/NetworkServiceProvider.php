<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeHandler;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeHandler;
use Logistics\Network\Application\Query\FindNode\FindNodeHandler;
use Logistics\Network\Application\Query\FindNode\FindNodeQuery;
use Logistics\Network\Application\Query\ListNodes\ListNodesHandler;
use Logistics\Network\Application\Query\ListNodes\ListNodesQuery;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Infrastructure\Persistence\Eloquent\EloquentNodeRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Red logística: nodos y tramos.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NodeRepository::class, EloquentNodeRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(RegisterNodeCommand::class, RegisterNodeHandler::class);
        $registry->register(DeactivateNodeCommand::class, DeactivateNodeHandler::class);
        $registry->register(ListNodesQuery::class, ListNodesHandler::class);
        $registry->register(FindNodeQuery::class, FindNodeHandler::class);
    }
}
