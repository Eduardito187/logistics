<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromiseHandler;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservationHandler;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromiseHandler;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromiseHandler;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\Query\GetPromise\GetPromiseHandler;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlotsHandler;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Infrastructure\Availability\StubAvailabilityProvider;
use Logistics\Promise\Infrastructure\Availability\StubSlotReserver;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\EloquentPromiseRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Promesa de entrega.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class PromiseServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        QuotePromise::class => QuotePromiseHandler::class,
        ReservePromise::class => ReservePromiseHandler::class,
        ConfirmPromise::class => ConfirmPromiseHandler::class,
        ExpireReservation::class => ExpireReservationHandler::class,
        ListAvailableSlots::class => ListAvailableSlotsHandler::class,
        GetPromise::class => GetPromiseHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(PromiseRepository::class, EloquentPromiseRepository::class);

        // Stubs deterministas de los puertos hacia capacity/coverage. Son singletons para que el cupo
        // tomado en memoria por StubSlotReserver persista durante el request mientras no haya integración.
        $this->app->singleton(AvailabilityProvider::class, StubAvailabilityProvider::class);
        $this->app->singleton(SlotReserver::class, StubSlotReserver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
