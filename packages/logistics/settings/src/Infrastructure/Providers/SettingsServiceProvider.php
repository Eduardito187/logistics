<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Command\SetSetting\SetSettingHandler;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHandler;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryHandler;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Infrastructure\Cache\CacheInvalidatingSettingsRepository;
use Logistics\Settings\Infrastructure\Cache\CachingGetSettingHandler;
use Logistics\Settings\Infrastructure\Integration\IntegrationRuntimeOverrides;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\EloquentSettingsRepository;
use Logistics\Settings\Presentation\Console\GetSettingCommand;
use Logistics\Shared\Application\HandlerRegistry;
use Throwable;

/**
 * Registro del bounded context Configuración (reglas versionadas).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que
 * el orden lo controle el proyecto. Los handlers se mapean en boot() contra el HandlerRegistry
 * singleton del núcleo compartido, ya resuelto en ese punto.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositorio de configuración envuelto por el decorador que invalida el cache del valor vigente
        // (frente escala): persiste igual que el Eloquent y, al publicar una versión nueva, olvida el valor
        // cacheado de esa clave. No cachea lecturas (el cálculo de la versión siguiente lee de la base).
        $this->app->bind(SettingsRepository::class, function ($app): SettingsRepository {
            return new CacheInvalidatingSettingsRepository(
                $app->make(EloquentSettingsRepository::class),
                $app->make(CacheRepository::class),
            );
        });

        // Lectura del valor vigente "a ahora" con cache (Valkey) en forma primitiva (regla #7), invalidada
        // por evento desde el repositorio. El handler plano queda como dependencia interna del decorador.
        $this->app->bind(CachingGetSettingHandler::class, function ($app): CachingGetSettingHandler {
            return new CachingGetSettingHandler(
                $app->make(GetSettingHandler::class),
                $app->make(CacheRepository::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas del package (panel de Integraciones del admin).
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-settings');

        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(SetSettingCommand::class, SetSettingHandler::class);
        $registry->register(GetSettingQuery::class, CachingGetSettingHandler::class);
        $registry->register(GetSettingHistoryQuery::class, GetSettingHistoryHandler::class);

        if ($this->app->runningInConsole()) {
            $this->commands([GetSettingCommand::class]);
        }

        $this->applyIntegrationOverrides();
    }

    /**
     * Overlaya en config() las integraciones configuradas desde el panel (ruteo, SAP, almacenamiento),
     * para que se lean en runtime con fallback a env. Degrada en silencio si la DB aún no está lista
     * (p. ej. durante la primera migración), para no romper el arranque.
     *
     * Corre en el boot de CADA request: no consulta el catálogo (`Schema::hasTable` costaba una consulta
     * a pg_class por request, ~10 ms incluyendo abrir la conexión) ni la base en el caso caliente —
     * IntegrationConfig lee el valor vigente de cache (Valkey) y, si la tabla aún no existe, su propio
     * try/catch degrada a "no configurado".
     */
    private function applyIntegrationOverrides(): void
    {
        try {
            $this->app->make(IntegrationRuntimeOverrides::class)->apply();
        } catch (Throwable) {
            // Sin DB / sin tabla: la plataforma usa la config por env tal cual.
        }
    }
}
