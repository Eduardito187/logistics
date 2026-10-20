<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascade;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascadeHandler;
use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSent;
use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSentHandler;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotificationHandler;
use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRule;
use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRuleHandler;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRuleHandler;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotification;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotificationHandler;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplate;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplateHandler;
use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Application\Query\GetMessageTemplate\GetMessageTemplate;
use Logistics\Notifications\Application\Query\GetMessageTemplate\GetMessageTemplateHandler;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\Query\GetNotification\GetNotificationHandler;
use Logistics\Notifications\Application\Query\GetSmsBudget\GetSmsBudget;
use Logistics\Notifications\Application\Query\GetSmsBudget\GetSmsBudgetHandler;
use Logistics\Notifications\Application\Query\ListMessageTemplates\ListMessageTemplates;
use Logistics\Notifications\Application\Query\ListMessageTemplates\ListMessageTemplatesHandler;
use Logistics\Notifications\Application\Query\ListMessageTypeRules\ListMessageTypeRules;
use Logistics\Notifications\Application\Query\ListMessageTypeRules\ListMessageTypeRulesHandler;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotificationsHandler;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipment;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipmentHandler;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\CollageImageBuilder;
use Logistics\Notifications\Domain\Contract\CollageImageStore;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Contract\NotificationVariableProvider;
use Logistics\Notifications\Domain\Contract\ProductImageResolver;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Infrastructure\Cache\CachingMessageTemplateRepository;
use Logistics\Notifications\Infrastructure\Cache\CachingMessageTypeRuleRepository;
use Logistics\Notifications\Infrastructure\Channel\EmailChannelSender;
use Logistics\Notifications\Infrastructure\Channel\FcmChannelSender;
use Logistics\Notifications\Infrastructure\Channel\NotificationContentRenderer;
use Logistics\Notifications\Infrastructure\Channel\RoutingChannelSender;
use Logistics\Notifications\Infrastructure\Channel\SmsChannelSender;
use Logistics\Notifications\Infrastructure\Channel\TelegramChannelSender;
use Logistics\Notifications\Infrastructure\Dispatch\DomainEventNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\FleetDocumentExpiryNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\NotificationChannelDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\VehicleIncidentNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Fcm\FcmAccessToken;
use Logistics\Notifications\Infrastructure\Image\FilesystemCollageImageStore;
use Logistics\Notifications\Infrastructure\Image\GdCollageImageBuilder;
use Logistics\Notifications\Infrastructure\Image\NullCollageImageBuilder;
use Logistics\Notifications\Infrastructure\Image\PushImageResolver;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentMessageTemplateRepository;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentMessageTypeRuleRepository;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentNotificationRepository;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentSmsBudgetRepository;
use Logistics\Notifications\Infrastructure\Product\LineItemProductImageResolver;
use Logistics\Notifications\Infrastructure\Rules\SettingsNotificationRulesProvider;
use Logistics\Notifications\Infrastructure\Settings\SettingsCascadeRuleGateway;
use Logistics\Notifications\Infrastructure\Variable\ShipmentNotificationVariableProvider;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Domain\Clock\Clock;
use Psr\Log\LoggerInterface;

/**
 * Registro del bounded context Notificaciones al cliente (ADR 006).
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
        MarkNotificationSent::class => MarkNotificationSentHandler::class,
        SuppressNotification::class => SuppressNotificationHandler::class,
        SetMessageTypeRule::class => SetMessageTypeRuleHandler::class,
        ResetMessageTypeRule::class => ResetMessageTypeRuleHandler::class,
        UpsertMessageTemplate::class => UpsertMessageTemplateHandler::class,
        ListNotificationsByShipment::class => ListNotificationsByShipmentHandler::class,
        ListNotifications::class => ListNotificationsHandler::class,
        GetNotification::class => GetNotificationHandler::class,
        ListMessageTypeRules::class => ListMessageTypeRulesHandler::class,
        ListMessageTemplates::class => ListMessageTemplatesHandler::class,
        GetMessageTemplate::class => GetMessageTemplateHandler::class,
        GetSmsBudget::class => GetSmsBudgetHandler::class,
    ];

    private const CONFIG_PATH = __DIR__.'/../../../config/logistics-notifications.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'logistics-notifications');

        // Persistencia (puertos → Eloquent).
        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        // Plantillas y reglas de cascada son datos de referencia editables y muy leídos (listados del
        // panel): se envuelve el repositorio Eloquent con el decorador de cache (Valkey), que cachea all()
        // en forma primitiva (regla #7) y lo invalida al guardar/remover. La resolución por tipo del render
        // (findFor/policyFor) sigue yendo a la base.
        $this->app->bind(MessageTemplateRepository::class, function ($app): MessageTemplateRepository {
            return new CachingMessageTemplateRepository(
                $app->make(EloquentMessageTemplateRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(MessageTypeRuleRepository::class, function ($app): MessageTypeRuleRepository {
            return new CachingMessageTypeRuleRepository(
                $app->make(EloquentMessageTypeRuleRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(SmsBudgetRepository::class, EloquentSmsBudgetRepository::class);

        // Reglas de cascada: la FUENTE DE VERDAD es el contexto Configuración (versionado, con historial).
        // El puerto CascadeRuleSettings traduce a/desde esa configuración por los buses CQRS; se bindea como
        // `scoped` porque memoiza la lectura dentro del request/job (se reinicia entre requests, Octane-safe).
        $this->app->scoped(CascadeRuleSettings::class, SettingsCascadeRuleGateway::class);

        // El motor LEE la política efectiva desde Configuración y cae al default del ADR 006 por tipo.
        $this->app->bind(NotificationRulesProvider::class, SettingsNotificationRulesProvider::class);

        // Proveedor de variables de interpolación de plantillas (lee envío + tracking, tolerante).
        $this->app->bind(NotificationVariableProvider::class, function ($app): ShipmentNotificationVariableProvider {
            // El DatabaseManager (alias 'db') implementa ConnectionResolverInterface; el contrato no
            // está aliado por defecto, así que se resuelve por el alias.
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new ShipmentNotificationVariableProvider($connections);
        });

        $this->registerRichContent();
        $this->registerChannels();
        $this->registerHandlerBindings();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'notifications');

        $this->publishes([
            self::CONFIG_PATH => config_path('logistics-notifications.php'),
        ], 'logistics-notifications-config');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }

    /**
     * Registra los servicios de contenido rico: resolutor de productos del pedido, armador del collage
     * con GD (o su stub si está deshabilitado), store del PNG y el resolutor de imagen de push.
     */
    private function registerRichContent(): void
    {
        // Resolutor de productos del pedido (nombre, cantidad, imagen). Lee las líneas del envío.
        $this->app->bind(ProductImageResolver::class, function ($app): LineItemProductImageResolver {
            /** @var array<string, mixed> $rich */
            $rich = $app['config']->get('logistics-notifications.rich_content', []);

            // El DatabaseManager (alias 'db') implementa ConnectionResolverInterface; el contrato no
            // está aliado por defecto, así que se resuelve por el alias.
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new LineItemProductImageResolver(
                $connections,
                is_string($rich['placeholder_url'] ?? null) ? $rich['placeholder_url'] : null,
            );
        });

        // Armador del collage: GD si está habilitado y cargado; si no, el stub (push sin imagen).
        $this->app->bind(CollageImageBuilder::class, function ($app): CollageImageBuilder {
            /** @var array<string, mixed> $rich */
            $rich = $app['config']->get('logistics-notifications.rich_content', []);
            $enabled = (bool) ($rich['collage_enabled'] ?? true);
            if (!$enabled || !extension_loaded('gd')) {
                return new NullCollageImageBuilder;
            }

            return new GdCollageImageBuilder((int) ($rich['image_timeout'] ?? 5));
        });

        // Store del PNG del collage en un disco de Laravel (local en dev, s3 en producción).
        $this->app->bind(CollageImageStore::class, function ($app): FilesystemCollageImageStore {
            /** @var array<string, mixed> $rich */
            $rich = $app['config']->get('logistics-notifications.rich_content', []);

            return new FilesystemCollageImageStore(
                (string) ($rich['collage_disk'] ?? 'public'),
                (string) ($rich['collage_directory'] ?? 'collages'),
            );
        });

        // Resolutor de la URL de imagen de push (compone resolutor + armador + store).
        $this->app->singleton(PushImageResolver::class, function ($app): PushImageResolver {
            return new PushImageResolver(
                $app->make(ProductImageResolver::class),
                $app->make(CollageImageBuilder::class),
                $app->make(CollageImageStore::class),
                $app->make(LoggerInterface::class),
            );
        });
    }

    /**
     * Arma los canales reales detrás del contrato ChannelSender y el dispatcher del outbox: push por
     * Firebase Cloud Messaging (FCM, por topic), Telegram, email y SMS por gateway open-source.
     */
    private function registerChannels(): void
    {
        // Binding de producción del ChannelSender: enruta por canal a los senders reales.
        $this->app->singleton(ChannelSender::class, function ($app): RoutingChannelSender {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('logistics-notifications');
            $pushImages = $app->make(PushImageResolver::class);

            /** @var array{project_id: ?string, credentials: ?string, timeout: int} $fcmConfig */
            $fcmConfig = [
                'project_id' => $config['fcm']['project_id'] ?? null,
                'credentials' => $config['fcm']['credentials'] ?? null,
                'timeout' => (int) ($config['fcm']['timeout'] ?? 10),
            ];
            /** @var array{endpoint: string, bot_token: ?string, timeout: int} $telegramConfig */
            $telegramConfig = [
                'endpoint' => (string) ($config['telegram']['endpoint'] ?? 'https://api.telegram.org'),
                'bot_token' => $config['telegram']['bot_token'] ?? null,
                'timeout' => (int) ($config['telegram']['timeout'] ?? 5),
            ];

            return new RoutingChannelSender([
                Channel::Fcm->value => new FcmChannelSender(
                    ['project_id' => $fcmConfig['project_id'], 'timeout' => $fcmConfig['timeout']],
                    new FcmAccessToken($fcmConfig),
                    $pushImages,
                ),
                Channel::Telegram->value => new TelegramChannelSender($telegramConfig, $pushImages),
                Channel::Email->value => new EmailChannelSender(
                    is_string($config['email']['mailer'] ?? null) ? $config['email']['mailer'] : null,
                    (string) ($config['email']['from_address'] ?? 'no-reply@dismac.test'),
                    (string) ($config['email']['from_name'] ?? 'Dismac Logistics'),
                    $app->make(ViewFactory::class),
                    $app->make(ProductImageResolver::class),
                ),
                Channel::Sms->value => new SmsChannelSender(
                    $app->make(SmsBudgetRepository::class),
                    $app->make(Clock::class),
                    [
                        'url' => $config['sms']['url'] ?? null,
                        'token' => $config['sms']['token'] ?? null,
                        'username' => $config['sms']['username'] ?? null,
                        'password' => $config['sms']['password'] ?? null,
                        'sender' => (string) ($config['sms']['sender'] ?? 'Dismac'),
                        'monthly_cap' => (int) ($config['sms']['monthly_cap'] ?? 0),
                        'timeout' => (int) ($config['sms']['timeout'] ?? 5),
                    ],
                ),
            ]);
        });

        // Renderizador de contenido (plantilla o default por tipo).
        $this->app->singleton(NotificationContentRenderer::class, function ($app): NotificationContentRenderer {
            return new NotificationContentRenderer(
                $app->make(MessageTemplateRepository::class),
                $app->make(NotificationVariableProvider::class),
                (string) $app['config']->get('logistics-notifications.default_locale', 'es'),
            );
        });

        // Dispatchers del outbox del contexto (los compone el integrador en el relay por eventName):
        // - NotificationChannelDispatcher: materializa el envío real de las `notifications.*`.
        // - DomainEventNotificationDispatcher: ante `shipments.*`/`execution.*`/`tracking.*`, encola la
        //   notificación al cliente (QueueNotification), de forma idempotente.
        $this->app->bind(NotificationChannelDispatcher::class);
        $this->app->bind(DomainEventNotificationDispatcher::class);

        // - VehicleIncidentNotificationDispatcher: ante execution.vehicle_incident_reported, avisa a los
        //   clientes afectados (reprogramado / en camino con retraso) y al supervisor (contactos de la
        //   config). Lleva escalares, así que se arma a mano.
        $this->app->bind(VehicleIncidentNotificationDispatcher::class, function ($app): VehicleIncidentNotificationDispatcher {
            $config = $app->make('config');
            $email = $config->get('logistics-notifications.supervisor.email');
            $phone = $config->get('logistics-notifications.supervisor.phone');

            return new VehicleIncidentNotificationDispatcher(
                $app->make(NotificationRepository::class),
                $app->make(CommandBus::class),
                is_string($email) && trim($email) !== '' ? $email : null,
                is_string($phone) && trim($phone) !== '' ? $phone : null,
            );
        });

        // - FleetDocumentExpiryNotificationDispatcher: ante fleet.documents_expiry_alerted, avisa al
        //   supervisor con un correo consolidado del barrido de documentos por vencer / vencidos. Es una
        //   alerta interna (no anclada a un envío), así que sale directo por el mailer; si no hay email de
        //   supervisor, queda en el log. Lleva escalares, así que se arma a mano.
        $this->app->bind(FleetDocumentExpiryNotificationDispatcher::class, function ($app): FleetDocumentExpiryNotificationDispatcher {
            $config = $app->make('config');
            $email = $config->get('logistics-notifications.supervisor.email');
            $mailer = $config->get('logistics-notifications.email.mailer');

            return new FleetDocumentExpiryNotificationDispatcher(
                $app->make(LoggerInterface::class),
                is_string($email) && trim($email) !== '' ? $email : null,
                (string) $config->get('logistics-notifications.email.from_address', 'no-reply@dismac.test'),
                (string) $config->get('logistics-notifications.email.from_name', 'Dismac Logistics'),
                is_string($mailer) && $mailer !== '' ? $mailer : null,
            );
        });
    }

    /**
     * Bindings contextuales de los escalares que no se pueden autoinyectar.
     */
    private function registerHandlerBindings(): void
    {
        $this->app->when(GetSmsBudgetHandler::class)
            ->needs('$monthlyCap')
            ->give(fn ($app): int => (int) $app['config']->get('logistics-notifications.sms.monthly_cap', 0));
    }
}
