<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Infrastructure\Bus\LaravelCommandBus;
use Logistics\Shared\Infrastructure\Bus\LaravelQueryBus;
use Logistics\Shared\Infrastructure\Clock\SystemClock;

/**
 * Registro del núcleo compartido. Va primero en bootstrap/providers.php: todos los contextos
 * registran sus handlers contra el HandlerRegistry singleton que vive acá.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandlerRegistry::class);

        $this->app->singleton(CommandBus::class, function ($app): LaravelCommandBus {
            return new LaravelCommandBus($app, $app->make(HandlerRegistry::class));
        });

        $this->app->singleton(QueryBus::class, function ($app): LaravelQueryBus {
            return new LaravelQueryBus($app, $app->make(HandlerRegistry::class));
        });

        $this->app->bind(Clock::class, SystemClock::class);
    }
}
