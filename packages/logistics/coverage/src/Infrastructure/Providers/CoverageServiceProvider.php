<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Command\DefineZoneHandler;
use Logistics\Coverage\Application\Command\ImportCoverage;
use Logistics\Coverage\Application\Command\ImportCoverageHandler;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Application\Command\ImportCoverageKmlHandler;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Application\Command\ImportCoverageMapHandler;
use Logistics\Coverage\Application\Command\UpdateZoneRules;
use Logistics\Coverage\Application\Command\UpdateZoneRulesHandler;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\Contract\ZoneRuleSettings;
use Logistics\Coverage\Application\Query\GetEffectiveZoneRule;
use Logistics\Coverage\Application\Query\GetEffectiveZoneRuleHandler;
use Logistics\Coverage\Application\Query\ListMunicipalities;
use Logistics\Coverage\Application\Query\ListMunicipalitiesHandler;
use Logistics\Coverage\Application\Query\ListZones;
use Logistics\Coverage\Application\Query\ListZonesHandler;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPoint;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPointHandler;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\Query\ResolveZoneForPointHandler;
use Logistics\Coverage\Application\Report\ImportCoverageReport;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Infrastructure\Cache\CachingGeoAreaRepository;
use Logistics\Coverage\Infrastructure\Cache\CachingMunicipalityCatalog;
use Logistics\Coverage\Infrastructure\Cache\CachingZoneRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\EloquentGeoAreaRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\EloquentMunicipalityCatalog;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\EloquentZoneRepository;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\PostgisDepartmentLocator;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\PostgisMunicipalityLocator;
use Logistics\Coverage\Infrastructure\Settings\SettingsZoneRuleGateway;
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
        ImportCoverage::class => ImportCoverageHandler::class,
        ResolveZoneForPoint::class => ResolveZoneForPointHandler::class,
        ResolveDepartmentForPoint::class => ResolveDepartmentForPointHandler::class,
        GetEffectiveZoneRule::class => GetEffectiveZoneRuleHandler::class,
        ListZones::class => ListZonesHandler::class,
        ListMunicipalities::class => ListMunicipalitiesHandler::class,
    ];

    public function register(): void
    {
        // Zonas, jerarquía administrativa y municipios son catálogos de referencia muy estables y muy
        // leídos: se envuelve cada implementación con su decorador de cache (Valkey), que cachea all() en
        // forma primitiva (regla #7) y lo invalida al guardar. La resolución espacial (PostGIS) y las
        // lecturas puntuales siguen yendo a la base. El catálogo de municipios se deriva de la jerarquía,
        // así que su invalidación la dispara el guardado de un área.
        $this->app->bind(ZoneRepository::class, function ($app): ZoneRepository {
            return new CachingZoneRepository(
                $app->make(EloquentZoneRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(GeoAreaRepository::class, function ($app): GeoAreaRepository {
            return new CachingGeoAreaRepository(
                $app->make(EloquentGeoAreaRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(DepartmentLocator::class, PostgisDepartmentLocator::class);
        $this->app->bind(MunicipalityLocator::class, PostgisMunicipalityLocator::class);
        $this->app->bind(MunicipalityCatalog::class, function ($app): MunicipalityCatalog {
            return new CachingMunicipalityCatalog(
                $app->make(EloquentMunicipalityCatalog::class),
                $app->make(CacheRepository::class),
            );
        });

        // Regla de cobertura por zona como configuración versionada: el adaptador habla con el contexto
        // Configuración solo por los buses CQRS (sin dependencia composer).
        $this->app->bind(ZoneRuleSettings::class, SettingsZoneRuleGateway::class);

        // Colector del resultado de la importación de cobertura: singleton de alcance de request,
        // compartido entre el handler (que lo llena) y la acción de admin (que lo lee tras el dispatch).
        $this->app->singleton(ImportCoverageReport::class);
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
