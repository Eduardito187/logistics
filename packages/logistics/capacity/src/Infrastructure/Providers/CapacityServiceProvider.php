<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityHandler;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityHandler;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityHandler;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityHandler;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateHandler;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\EloquentCapacityRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Capacity: cupos en unidades de capacidad.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class CapacityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CapacityRepository::class, EloquentCapacityRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(DefineCapacityCommand::class, DefineCapacityHandler::class);
        $registry->register(ConsumeCapacityCommand::class, ConsumeCapacityHandler::class);
        $registry->register(ReleaseCapacityCommand::class, ReleaseCapacityHandler::class);
        $registry->register(GetAvailableCapacityQuery::class, GetAvailableCapacityHandler::class);
        $registry->register(ListBucketsByDateQuery::class, ListBucketsByDateHandler::class);
    }
}
