<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeHandler;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\DefineLeg\DefineLegHandler;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeHandler;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockCommand;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockHandler;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegCommand;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegHandler;
use Logistics\Network\Application\Query\FindLeg\FindLegHandler;
use Logistics\Network\Application\Query\FindLeg\FindLegQuery;
use Logistics\Network\Application\Query\FindNode\FindNodeHandler;
use Logistics\Network\Application\Query\FindNode\FindNodeQuery;
use Logistics\Network\Application\Query\ListLegs\ListLegsHandler;
use Logistics\Network\Application\Query\ListLegs\ListLegsQuery;
use Logistics\Network\Application\Query\ListNodes\ListNodesHandler;
use Logistics\Network\Application\Query\ListNodes\ListNodesQuery;
use Logistics\Network\Application\Query\ListReceivingDocks\ListReceivingDocksHandler;
use Logistics\Network\Application\Query\ListReceivingDocks\ListReceivingDocksQuery;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentHandler;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentQuery;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Contract\ReceivingDockRepository;
use Logistics\Network\Infrastructure\Cache\CachingLegRepository;
use Logistics\Network\Infrastructure\Cache\CachingNodeRepository;
use Logistics\Network\Infrastructure\Persistence\Eloquent\EloquentLegRepository;
use Logistics\Network\Infrastructure\Persistence\Eloquent\EloquentNodeRepository;
use Logistics\Network\Infrastructure\Persistence\Eloquent\EloquentReceivingDockRepository;
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
        // Nodos y tramos son datos de referencia muy leídos (listados y cálculo de corredores por
        // departamento que consultan promise/planning): se envuelve el repositorio Eloquent con el
        // decorador de cache (Valkey), que cachea all() en forma primitiva (regla #7) y lo invalida al
        // guardar/remover. Las lecturas puntuales y las escrituras siguen yendo a la base.
        $this->app->bind(NodeRepository::class, function ($app): NodeRepository {
            return new CachingNodeRepository(
                $app->make(EloquentNodeRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(LegRepository::class, function ($app): LegRepository {
            return new CachingLegRepository(
                $app->make(EloquentLegRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(ReceivingDockRepository::class, EloquentReceivingDockRepository::class);
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

        // Tramos/corredores entre nodos.
        $registry->register(DefineLegCommand::class, DefineLegHandler::class);
        $registry->register(RemoveLegCommand::class, RemoveLegHandler::class);
        $registry->register(ListLegsQuery::class, ListLegsHandler::class);
        $registry->register(FindLegQuery::class, FindLegHandler::class);

        // Corredores operativos por departamento: insumo de planning/promise.
        $registry->register(
            OperationalCorridorsByDepartmentQuery::class,
            OperationalCorridorsByDepartmentHandler::class,
        );

        // Andenes de recepción de almacenes centrales (marketplace/inbound).
        $registry->register(RegisterReceivingDockCommand::class, RegisterReceivingDockHandler::class);
        $registry->register(ListReceivingDocksQuery::class, ListReceivingDocksHandler::class);
    }
}
