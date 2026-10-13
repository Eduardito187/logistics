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
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodeScoring;
use Logistics\Shipments\Infrastructure\Geocoding\GoogleGeocoder;
use Logistics\Shipments\Infrastructure\Geocoding\NominatimGeocoder;
use Logistics\Shipments\Infrastructure\Geocoding\NullGeocoder;
use Logistics\Shipments\Infrastructure\Geocoding\ProvisionalAddressConfidenceScorer;
use Logistics\Shipments\Infrastructure\Ingestion\CsvShipmentSpreadsheetParser;
use Logistics\Shipments\Infrastructure\Ingestion\DelegatingShipmentSpreadsheetParser;
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

        // Parser de planillas de carga (SHP-06): detecta el formato por el contenido. Hoy procesa CSV
        // (librería estándar, sin dependencias) y reconoce binarios de Excel para devolver un error
        // accionable (exportar a CSV) en vez de leerlos como texto.
        $this->app->bind(ShipmentSpreadsheetParser::class, function ($app): ShipmentSpreadsheetParser {
            return new DelegatingShipmentSpreadsheetParser($app->make(CsvShipmentSpreadsheetParser::class));
        });

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

        // Geocodificador de direcciones: Google (si hay clave), Nominatim/OSM (sin clave) o el stub
        // neutro, según config (driver) con AUTO por defecto, para que la app arranque y se desarrolle
        // sin credenciales (ADR: I/O externo configurable y degradable). Se resuelve lazy, al primer uso.
        $this->app->singleton(Geocoder::class, function ($app): Geocoder {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return self::makeGeocoder($app->make(HttpClient::class), $app->make(CacheFactory::class), $config);
        });

        // Scorer del INGRESO (y del seeder): provisional, local y sin I/O. El ingreso corre dentro de un
        // request/transacción de usuario, donde no se hace HTTP síncrono a terceros (regla del proyecto):
        // la geocodificación real (el Geocoder de arriba) la hace después, asíncrona, el comando
        // GeocodeShipmentAddress vía el worker del outbox.
        $this->app->bind(AddressConfidenceScorer::class, function ($app): AddressConfidenceScorer {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new ProvisionalAddressConfidenceScorer(
                (int) $config->get('logistics-shipments.geocoder.provisional.with_coordinates', 70),
                (int) $config->get('logistics-shipments.geocoder.provisional.without_coordinates', 30),
            );
        });
    }

    /**
     * Selecciona y arma el geocodificador según la configuración del contexto.
     */
    private static function makeGeocoder(HttpClient $http, CacheFactory $cacheFactory, Config $config): Geocoder
    {
        $driver = $config->get('logistics-shipments.geocoder.driver');
        $driver = is_string($driver) && $driver !== '' ? strtolower($driver) : null;

        $googleKey = $config->get('logistics-shipments.geocoder.google.key');
        $hasGoogleKey = is_string($googleKey) && $googleKey !== '';

        $nominatimEnabled = (bool) $config->get('logistics-shipments.geocoder.nominatim.enabled', true);

        // AUTO: Google si hay clave; si no, Nominatim si está habilitado; si no, el stub neutro.
        if ($driver === null) {
            $driver = match (true) {
                $hasGoogleKey => 'google',
                $nominatimEnabled => 'nominatim',
                default => 'null',
            };
        }

        $store = $config->get('logistics-shipments.geocoder.cache_store');
        $cache = $cacheFactory->store(is_string($store) && $store !== '' ? $store : null);

        $scoringConfig = $config->get('logistics-shipments.geocoder.scoring');
        $scoring = GeocodeScoring::fromConfig(is_array($scoringConfig) ? $scoringConfig : []);

        $cacheTtl = (int) $config->get('logistics-shipments.geocoder.cache_ttl', 2592000);

        if ($driver === 'google' && $hasGoogleKey) {
            return new GoogleGeocoder(
                $http,
                $cache,
                $scoring,
                (string) $googleKey,
                (string) $config->get('logistics-shipments.geocoder.google.endpoint'),
                (string) $config->get('logistics-shipments.geocoder.google.region', 'bo'),
                (string) $config->get('logistics-shipments.geocoder.google.language', 'es'),
                (int) $config->get('logistics-shipments.geocoder.google.timeout', 5),
                $cacheTtl,
                (int) $config->get('logistics-shipments.geocoder.google.daily_quota', 40000),
            );
        }

        if ($driver === 'nominatim') {
            return new NominatimGeocoder(
                $http,
                $cache,
                $scoring,
                (string) $config->get('logistics-shipments.geocoder.nominatim.endpoint'),
                (string) $config->get('logistics-shipments.geocoder.nominatim.user_agent'),
                (string) $config->get('logistics-shipments.geocoder.nominatim.country_codes', 'bo'),
                (string) $config->get('logistics-shipments.geocoder.nominatim.language', 'es'),
                (int) $config->get('logistics-shipments.geocoder.nominatim.timeout', 5),
                $cacheTtl,
                (int) $config->get('logistics-shipments.geocoder.nominatim.min_interval_ms', 1000),
            );
        }

        return new NullGeocoder;
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
