<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddress;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddressHandler;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipmentHandler;
use Logistics\Shipments\Application\Command\SetDeliverySlot\SetDeliverySlot;
use Logistics\Shipments\Application\Command\SetDeliverySlot\SetDeliverySlotHandler;
use Logistics\Shipments\Application\Contract\MagentoOrderReader;
use Logistics\Shipments\Application\Contract\ShipmentSpreadsheetParser;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\Query\FindShipment\FindShipmentHandler;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddresses;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddressesHandler;
use Logistics\Shipments\Application\Query\ListShipmentProducts\ListShipmentProducts;
use Logistics\Shipments\Application\Query\ListShipmentProducts\ListShipmentProductsHandler;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipments\ListShipmentsHandler;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Infrastructure\Geocoding\GoogleGeocoder;
use Logistics\Shipments\Infrastructure\Geocoding\NullAddressConfidenceScorer;
use Logistics\Shipments\Infrastructure\Ingestion\CsvShipmentSpreadsheetParser;
use Logistics\Shipments\Infrastructure\Magento\HttpMagentoOrderReader;
use Logistics\Shipments\Infrastructure\Magento\NullMagentoOrderReader;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\EloquentShipmentRepository;
use Logistics\Shipments\Presentation\Console\PullMagentoOrdersCommand;
use Logistics\Shipments\Presentation\Console\ReceiveManualShipmentCommand;

/**
 * Registro del bounded context Recepción de envíos.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class ShipmentsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        ReceiveShipment::class => ReceiveShipmentHandler::class,
        SetDeliverySlot::class => SetDeliverySlotHandler::class,
        GeocodeShipmentAddress::class => GeocodeShipmentAddressHandler::class,
        FindShipment::class => FindShipmentHandler::class,
        ListShipments::class => ListShipmentsHandler::class,
        ListShipmentProducts::class => ListShipmentProductsHandler::class,
        ListLowConfidenceAddresses::class => ListLowConfidenceAddressesHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-shipments.php', 'logistics-shipments');

        $this->app->bind(ShipmentRepository::class, EloquentShipmentRepository::class);

        // Parser de planillas de carga (SHP-06): CSV con la librería estándar, sin dependencias.
        $this->app->bind(ShipmentSpreadsheetParser::class, CsvShipmentSpreadsheetParser::class);

        // Lector de pedidos de Magento (SHP-07): adaptador HTTP real si hay base_url; si no, el stub
        // neutro, para que la app arranque sin credenciales. La selección se resuelve lazy, al primer uso.
        $this->app->singleton(MagentoOrderReader::class, function ($app): MagentoOrderReader {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $baseUrl = $config->get('logistics-shipments.magento.base_url');
            $accessToken = $config->get('logistics-shipments.magento.access_token');
            if (!is_string($baseUrl) || $baseUrl === '' || !is_string($accessToken) || $accessToken === '') {
                return new NullMagentoOrderReader;
            }

            return new HttpMagentoOrderReader(
                $app->make(HttpClient::class),
                $baseUrl,
                $accessToken,
                (string) $config->get('logistics-shipments.magento.status', 'processing'),
                (int) $config->get('logistics-shipments.magento.page_size', 100),
                (int) $config->get('logistics-shipments.magento.timeout', 10),
            );
        });

        // Scorer/geocodificador de direcciones: adaptador real de Google si hay clave; si no, el stub
        // neutro, para que la app arranque y se pueda desarrollar sin credenciales (ADR: I/O externo
        // configurable y degradable). La selección se resuelve lazy, al primer uso.
        $this->app->singleton(AddressConfidenceScorer::class, function ($app): AddressConfidenceScorer {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $apiKey = $config->get('logistics-shipments.geocoder.google_key');
            if (!is_string($apiKey) || $apiKey === '') {
                return new NullAddressConfidenceScorer;
            }

            /** @var CacheFactory $cacheFactory */
            $cacheFactory = $app->make(CacheFactory::class);
            $store = $config->get('logistics-shipments.geocoder.cache_store');

            return new GoogleGeocoder(
                $app->make(HttpClient::class),
                $cacheFactory->store(is_string($store) && $store !== '' ? $store : null),
                $apiKey,
                (string) $config->get('logistics-shipments.geocoder.endpoint'),
                (string) $config->get('logistics-shipments.geocoder.region', 'bo'),
                (string) $config->get('logistics-shipments.geocoder.language', 'es'),
                (int) $config->get('logistics-shipments.geocoder.timeout', 5),
                (int) $config->get('logistics-shipments.geocoder.cache_ttl', 2592000),
                (int) $config->get('logistics-shipments.geocoder.daily_quota', 40000),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas del contexto (parciales Blade del admin: mapa de entrega y pedidos del cliente).
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-shipments');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReceiveManualShipmentCommand::class,
                PullMagentoOrdersCommand::class,
            ]);
        }
    }
}
