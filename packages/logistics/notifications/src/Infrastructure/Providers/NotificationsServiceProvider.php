<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascade;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascadeHandler;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotificationHandler;
use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipment;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipmentHandler;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Infrastructure\Channel\NullChannelSender;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentNotificationRepository;
use Logistics\Notifications\Infrastructure\Rules\DefaultNotificationRulesProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Notificaciones al cliente.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        QueueNotification::class => QueueNotificationHandler::class,
        AdvanceCascade::class => AdvanceCascadeHandler::class,
        ListNotificationsByShipment::class => ListNotificationsByShipmentHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(ChannelSender::class, NullChannelSender::class);
        $this->app->bind(NotificationRulesProvider::class, DefaultNotificationRulesProvider::class);
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
