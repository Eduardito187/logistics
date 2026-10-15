<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowCommand;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowHandler;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayHandler;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityHandler;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityHandler;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityHandler;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayCommand;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayHandler;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayHandler;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\Query\GetActiveHorizon\GetActiveHorizonHandler;
use Logistics\Capacity\Application\Query\GetActiveHorizon\GetActiveHorizonQuery;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityHandler;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityQuery;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetHandler;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetQuery;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityHandler;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityQuery;
use Logistics\Capacity\Application\Query\ListBlackoutDays\ListBlackoutDaysHandler;
use Logistics\Capacity\Application\Query\ListBlackoutDays\ListBlackoutDaysQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateHandler;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\Contract\CapacityActivationDispatcher;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRuleProvider;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Infrastructure\Cache\CacheInvalidatingCapacityRepository;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\EloquentActiveHorizonRepository;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\EloquentCapacityRepository;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\EloquentDispatchBlackoutRepository;
use Logistics\Capacity\Infrastructure\Queue\QueuedCapacityActivationDispatcher;
use Logistics\Capacity\Infrastructure\Settings\ConfiguredCapacityRuleProvider;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Capacity\Presentation\Console\GenerateDispatchCalendarCommand;
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

        // Repositorio de cupos envuelto por el decorador que invalida por evento (frente E — escala):
        // guarda igual que el Eloquent y, tras cada save, sube el contador de generación del día para
        // dejar fríos los caches que derivan de la capacidad (p. ej. las franjas disponibles de promise).
        // No cachea lecturas, así los locks pesimistas del consumo siguen golpeando la base intactos.
        $this->app->bind(CapacityRepository::class, function ($app): CapacityRepository {
            return new CacheInvalidatingCapacityRepository(
                $app->make(EloquentCapacityRepository::class),
                $app->make(CacheRepository::class),
            );
        });

        // Reglas de cupo configurables (P2-CAP-03). Hoy las sirve la config del package; el día que
        // pasen a logistics/settings se reemplaza solo este binding, sin tocar dominio ni handlers.
        $this->app->bind(CapacityRuleProvider::class, ConfiguredCapacityRuleProvider::class);

        // Conjunto completo de reglas de cupo versionadas (C-CAP-01): se leen de la config versionada de
        // logistics/settings (clave capacity.dispatch_rules) y, si no hay versión publicada, de la config
        // del package. Es el puerto que usan el generador de calendario y el editor de reglas del admin.
        $this->app->bind(CapacityRulesetProvider::class, SettingsCapacityRulesetProvider::class);

        // Feriados/bloqueos de despacho y horizonte activo de cupos por municipio (calendario de 7 días):
        // sus puertos de persistencia se resuelven a las implementaciones Eloquent.
        $this->app->bind(DispatchBlackoutRepository::class, EloquentDispatchBlackoutRepository::class);
        $this->app->bind(ActiveHorizonRepository::class, EloquentActiveHorizonRepository::class);

        // Activación por rango: el puerto de encolado se resuelve al dispatcher que empuja el job a la cola.
        $this->app->bind(CapacityActivationDispatcher::class, QueuedCapacityActivationDispatcher::class);
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
        // Calendario de 7 días: feriados/bloqueos y activación de cupos por rango y municipio.
        $registry->register(BlockDispatchDayCommand::class, BlockDispatchDayHandler::class);
        $registry->register(UnblockDispatchDayCommand::class, UnblockDispatchDayHandler::class);
        $registry->register(ActivateCapacityWindowCommand::class, ActivateCapacityWindowHandler::class);
        $registry->register(GetAvailableCapacityQuery::class, GetAvailableCapacityHandler::class);
        $registry->register(GetEffectiveCapacityQuery::class, GetEffectiveCapacityHandler::class);
        $registry->register(ListBucketsByDateQuery::class, ListBucketsByDateHandler::class);
        $registry->register(GetCapacityRulesetQuery::class, GetCapacityRulesetHandler::class);
        $registry->register(CheckDispatchDayQuery::class, CheckDispatchDayHandler::class);
        $registry->register(ListBlackoutDaysQuery::class, ListBlackoutDaysHandler::class);
        $registry->register(GetActiveHorizonQuery::class, GetActiveHorizonHandler::class);

        // El generador del calendario de cupos NO se despacha por el bus (orquesta DefineCapacity, cada
        // uno en su transacción); se expone como comando de consola que invoca su handler directo.
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateDispatchCalendarCommand::class]);
        }
    }
}
