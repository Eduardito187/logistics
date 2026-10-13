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
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityHandler;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateHandler;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRuleProvider;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\EloquentCapacityRepository;
use Logistics\Capacity\Infrastructure\Settings\ConfiguredCapacityRuleProvider;
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
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-capacity.php', 'logistics-capacity');

        $this->app->bind(CapacityRepository::class, EloquentCapacityRepository::class);

        // Reglas de cupo configurables (P2-CAP-03). Hoy las sirve la config del package; el día que
        // pasen a logistics/settings se reemplaza solo este binding, sin tocar dominio ni handlers.
        $this->app->bind(CapacityRuleProvider::class, ConfiguredCapacityRuleProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas del contexto (el calendario de despacho del admin vive en resources/views).
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-capacity');

        $this->publishes([
            __DIR__.'/../../../config/logistics-capacity.php' => config_path('logistics-capacity.php'),
        ], 'logistics-capacity-config');

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(DefineCapacityCommand::class, DefineCapacityHandler::class);
        $registry->register(ConsumeCapacityCommand::class, ConsumeCapacityHandler::class);
        $registry->register(ReleaseCapacityCommand::class, ReleaseCapacityHandler::class);
        $registry->register(GetAvailableCapacityQuery::class, GetAvailableCapacityHandler::class);
        $registry->register(GetEffectiveCapacityQuery::class, GetEffectiveCapacityHandler::class);
        $registry->register(ListBucketsByDateQuery::class, ListBucketsByDateHandler::class);
    }
}
