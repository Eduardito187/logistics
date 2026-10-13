<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Command\DefineZoneHandler;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Application\Command\ImportCoverageKmlHandler;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Application\Command\ImportCoverageMapHandler;
use Logistics\Coverage\Application\Command\UpdateZoneRules;
use Logistics\Coverage\Application\Command\UpdateZoneRulesHandler;
use Logistics\Coverage\Application\Query\ListZones;
use Logistics\Coverage\Application\Query\ListZonesHandler;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPoint;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPointHandler;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\Query\ResolveZoneForPointHandler;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\EloquentGeoAreaRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\EloquentZoneRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\PostgisDepartmentLocator;
use Logistics\Coverage\Presentation\Console\ResolveZoneCommand;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Cobertura.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class CoverageServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        DefineZone::class => DefineZoneHandler::class,
        UpdateZoneRules::class => UpdateZoneRulesHandler::class,
        ImportCoverageMap::class => ImportCoverageMapHandler::class,
        ImportCoverageKml::class => ImportCoverageKmlHandler::class,
        ResolveZoneForPoint::class => ResolveZoneForPointHandler::class,
        ResolveDepartmentForPoint::class => ResolveDepartmentForPointHandler::class,
        ListZones::class => ListZonesHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(ZoneRepository::class, EloquentZoneRepository::class);
        $this->app->bind(GeoAreaRepository::class, EloquentGeoAreaRepository::class);
        $this->app->bind(DepartmentLocator::class, PostgisDepartmentLocator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ResolveZoneCommand::class]);
        }
    }
}
