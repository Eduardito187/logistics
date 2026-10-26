<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Logistics\Outbox\Application\Command\DiscardMessage\DiscardMessageCommand;
use Logistics\Outbox\Application\Command\DiscardMessage\DiscardMessageHandler;
use Logistics\Outbox\Application\Command\RetryMessage\RetryMessageCommand;
use Logistics\Outbox\Application\Command\RetryMessage\RetryMessageHandler;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Application\Contract\OutboxMessageQueue;
use Logistics\Outbox\Application\EnqueueOutbox\EnqueueOutboxBatch;
use Logistics\Outbox\Application\Query\GetOutboxMessage\GetOutboxMessageHandler;
use Logistics\Outbox\Application\Query\GetOutboxMessage\GetOutboxMessageQuery;
use Logistics\Outbox\Application\Query\GetOutboxStats\GetOutboxStatsHandler;
use Logistics\Outbox\Application\Query\GetOutboxStats\GetOutboxStatsQuery;
use Logistics\Outbox\Application\Query\ListOutboxMessages\ListOutboxMessagesHandler;
use Logistics\Outbox\Application\Query\ListOutboxMessages\ListOutboxMessagesQuery;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Application\RelayOutbox\RelayOutboxBatch;
use Logistics\Outbox\Domain\Contract\OutboxMessageFinder;
use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Infrastructure\Dispatch\FanOutMessageDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\RoutingMessageDispatcher;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\EloquentOutboxReader;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\EloquentOutboxStore;
use Logistics\Outbox\Infrastructure\Queue\LaravelOutboxMessageQueue;
use Logistics\Outbox\Presentation\Console\PruneOutboxCommand;
use Logistics\Outbox\Presentation\Console\PumpOutboxCommand;
use Logistics\Outbox\Presentation\Console\RelayOutboxCommand;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Registro del bounded context Outbox.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-outbox.php', 'logistics-outbox');

        // Puerto de escritura: PostgreSQL vía Eloquent, con FOR UPDATE SKIP LOCKED + lease en el pull.
        $this->app->bind(OutboxStore::class, function (Container $app): EloquentOutboxStore {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            return new EloquentOutboxStore(
                $app->make(ConnectionResolverInterface::class),
                (int) $config->get('logistics-outbox.relay.lease_seconds', 60),
            );
        });

        // Puerto de lectura (lado query de CQRS): consultas sin bloqueo para panel y API.
        $this->app->bind(OutboxReader::class, EloquentOutboxReader::class);

        // Lookup puntual por id: lo resuelve el mismo store Eloquent (comparte lease_seconds).
        $this->app->bind(OutboxMessageFinder::class, static function (Container $app): EloquentOutboxStore {
            $store = $app->make(OutboxStore::class);
            assert($store instanceof EloquentOutboxStore);

            return $store;
        });

        // Dispatcher que rutea por nombre de evento hacia el adapter de cada canal (config 'routes').
        // Mientras un canal no tenga adapter, cae al dispatcher por defecto (hoy NullMessageDispatcher).
        $this->app->bind(MessageDispatcher::class, function (Container $app): RoutingMessageDispatcher {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var array<string, class-string<MessageDispatcher>> $routes */
            $routes = (array) $config->get('logistics-outbox.routes', []);
            /** @var class-string<MessageDispatcher> $default */
            $default = (string) $config->get('logistics-outbox.default_dispatcher');

            return new RoutingMessageDispatcher($app, $routes, $default);
        });

        // Abanico: entrega un mismo evento a varios adapters (uno por contexto que reacciona). El
        // RoutingMessageDispatcher rutea hacia acá los eventos con reacción multi-contexto (ver config
        // 'fanout'), p. ej. el incidente de camión (flota + planificación + notificaciones).
        $this->app->bind(FanOutMessageDispatcher::class, function (Container $app): FanOutMessageDispatcher {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var array<string, list<class-string<MessageDispatcher>>> $fanout */
            $fanout = (array) $config->get('logistics-outbox.fanout', []);

            return new FanOutMessageDispatcher($app, $fanout);
        });

        // Relay con parámetros afinables por config (max_attempts, backoff).
        $this->app->bind(RelayOutboxBatch::class, function (Container $app): RelayOutboxBatch {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var array<int, int> $backoff */
            $backoff = (array) $config->get('logistics-outbox.relay.backoff_minutes', []);

            return new RelayOutboxBatch(
                $app->make(OutboxStore::class),
                $app->make(MessageDispatcher::class),
                $app->make(Clock::class),
                (int) $config->get('logistics-outbox.relay.max_attempts', 6),
                $backoff !== [] ? $backoff : null,
            );
        });

        // Procesador de UN mensaje (misma política de backoff/dead-letter): lo usa el job encolado por
        // mensaje (C-2) y, por debajo, el relay sincrónico.
        $this->app->bind(ProcessOutboxMessage::class, function (Container $app): ProcessOutboxMessage {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var array<int, int> $backoff */
            $backoff = (array) $config->get('logistics-outbox.relay.backoff_minutes', []);

            return new ProcessOutboxMessage(
                $app->make(OutboxStore::class),
                $app->make(MessageDispatcher::class),
                (int) $config->get('logistics-outbox.relay.max_attempts', 6),
                $backoff !== [] ? $backoff : null,
            );
        });

        // Puerto de encolado: encola un job por mensaje a la cola de su destino (C-2). El ruteo por cola
        // aísla el head-of-line blocking entre destinos.
        $this->app->bind(OutboxMessageQueue::class, function (Container $app): LaravelOutboxMessageQueue {
            /** @var Repository $config */
            $config = $app->make(Repository::class);

            /** @var array<string, string> $routes */
            $routes = (array) $config->get('logistics-outbox.pump.queue_routes', []);

            return new LaravelOutboxMessageQueue(
                $routes,
                (string) $config->get('logistics-outbox.pump.default_queue', 'outbox'),
            );
        });

        // Dispatcher (pump): toma el lote y lo encola. Se resuelve por autowiring de sus puertos.
        $this->app->bind(EnqueueOutboxBatch::class, function (Container $app): EnqueueOutboxBatch {
            return new EnqueueOutboxBatch(
                $app->make(OutboxStore::class),
                $app->make(OutboxMessageQueue::class),
                $app->make(Clock::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $this->publishes([
            __DIR__.'/../../../config/logistics-outbox.php' => config_path('logistics-outbox.php'),
        ], 'logistics-outbox-config');

        // Handlers CQRS del contexto, contra el HandlerRegistry singleton del núcleo compartido.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(RetryMessageCommand::class, RetryMessageHandler::class);
        $registry->register(DiscardMessageCommand::class, DiscardMessageHandler::class);
        $registry->register(ListOutboxMessagesQuery::class, ListOutboxMessagesHandler::class);
        $registry->register(GetOutboxMessageQuery::class, GetOutboxMessageHandler::class);
        $registry->register(GetOutboxStatsQuery::class, GetOutboxStatsHandler::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutboxCommand::class, PumpOutboxCommand::class, PruneOutboxCommand::class]);
        }
    }
}
