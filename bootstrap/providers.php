<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\SlowQueryLoggingServiceProvider;
use Logistics\Access\Infrastructure\Providers\AccessServiceProvider;
use Logistics\Capacity\Infrastructure\Providers\CapacityServiceProvider;
use Logistics\Carriers\Infrastructure\Providers\CarriersServiceProvider;
use Logistics\Coverage\Infrastructure\Providers\CoverageServiceProvider;
use Logistics\Execution\Infrastructure\Providers\ExecutionServiceProvider;
use Logistics\Fleet\Infrastructure\Providers\FleetServiceProvider;
use Logistics\FleetOps\Infrastructure\Providers\FleetOpsServiceProvider;
use Logistics\Inbound\Infrastructure\Providers\InboundServiceProvider;
use Logistics\Inventory\Infrastructure\Providers\InventoryServiceProvider;
use Logistics\Labels\Infrastructure\Providers\LabelsServiceProvider;
use Logistics\Network\Infrastructure\Providers\NetworkServiceProvider;
use Logistics\Notifications\Infrastructure\Providers\NotificationsServiceProvider;
use Logistics\Outbox\Infrastructure\Providers\OutboxServiceProvider;
use Logistics\Planning\Infrastructure\Providers\PlanningServiceProvider;
use Logistics\Promise\Infrastructure\Providers\PromiseServiceProvider;
use Logistics\Returns\Infrastructure\Providers\ReturnsServiceProvider;
use Logistics\SapConnector\Infrastructure\Providers\SapConnectorServiceProvider;
use Logistics\SellerPortal\Infrastructure\Providers\SellerPortalServiceProvider;
use Logistics\Sellers\Infrastructure\Providers\SellersServiceProvider;
use Logistics\Settings\Infrastructure\Providers\SettingsServiceProvider;
use Logistics\Settlement\Infrastructure\Providers\SettlementServiceProvider;
use Logistics\Shared\Infrastructure\Providers\SharedServiceProvider;
use Logistics\Shipments\Infrastructure\Providers\ShipmentsServiceProvider;
use Logistics\Telemetry\Infrastructure\Providers\TelemetryServiceProvider;
use Logistics\Tracking\Infrastructure\Providers\TrackingServiceProvider;

// Única fuente de verdad del orden de registro: todos los logistics/* están en
// `extra.laravel.dont-discover` del composer.json raíz. Un package nuevo se agrega en
// los DOS lugares en el mismo cambio (bug de doble registro visto en dis-commerce).
//
// OJO: `php artisan filament:install` reescribe este archivo y pierde el orden; revisarlo
// después de correr cualquier instalador.
return [
    AppServiceProvider::class,

    // Observabilidad (auditoría M-6): registra el listener de consultas lentas (DB::listen) cuando
    // SLOW_QUERY_LOG_ENABLED está activo. Coste cero si no se habilita.
    SlowQueryLoggingServiceProvider::class,

    // Autenticación y autorización de las tres puertas de la API (Sanctum, roles,
    // credenciales por canal y de servicio). Temprano: expone alias de middleware,
    // rate limiters y gates que los contextos usan.
    AuthServiceProvider::class,

    // Núcleo compartido primero: expone el HandlerRegistry (CQRS) y los buses que los
    // contextos usan al registrar sus handlers, además del outbox y la configuración versionada.
    SharedServiceProvider::class,
    OutboxServiceProvider::class,
    SettingsServiceProvider::class,

    // Access: identidad, RBAC dinámico (roles y permisos) y tokens de integración. Autoridad de
    // autorización de la plataforma; aporta alias de middleware (access.token / access.permission) que
    // los contextos usan en sus rutas. Temprano, tras el núcleo compartido y el outbox del que depende.
    AccessServiceProvider::class,

    // Red y datos base
    NetworkServiceProvider::class,
    CoverageServiceProvider::class,
    FleetServiceProvider::class,
    FleetOpsServiceProvider::class,

    // Flujo de un envío
    ShipmentsServiceProvider::class,
    PlanningServiceProvider::class,
    LabelsServiceProvider::class,
    ExecutionServiceProvider::class,

    // Visibilidad
    TrackingServiceProvider::class,
    TelemetryServiceProvider::class,
    NotificationsServiceProvider::class,

    // Fase 2 — Prometer: cupos, promesa, conector SAP y transportistas
    CapacityServiceProvider::class,
    PromiseServiceProvider::class,
    SapConnectorServiceProvider::class,
    CarriersServiceProvider::class,

    // Marketplace / sellers (ADR 017, Fase 1): el seller como party. Depende solo del núcleo
    // compartido y el outbox (ya registrados arriba); el stock e inbound de terceros llegan en fases siguientes.
    SellersServiceProvider::class,

    // Marketplace fases 2-5 (ADR 017): recepción de proveedores (inbound usa capacity, ya registrado),
    // inventario con atribución de dueño, liquidación de sellers, devoluciones (logística inversa) y el
    // portal de autoservicio del seller (usa el token/permiso de Access, ya registrado arriba).
    InboundServiceProvider::class,
    InventoryServiceProvider::class,
    SettlementServiceProvider::class,
    ReturnsServiceProvider::class,
    SellerPortalServiceProvider::class,

    // Admin web (al final: los resources de cada contexto ya están registrados)
    AdminPanelProvider::class,
];
