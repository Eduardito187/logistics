<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Logistics\Access\Infrastructure\Providers\AccessServiceProvider;
use Logistics\Capacity\Infrastructure\Providers\CapacityServiceProvider;
use Logistics\Carriers\Infrastructure\Providers\CarriersServiceProvider;
use Logistics\Coverage\Infrastructure\Providers\CoverageServiceProvider;
use Logistics\Execution\Infrastructure\Providers\ExecutionServiceProvider;
use Logistics\Fleet\Infrastructure\Providers\FleetServiceProvider;
use Logistics\Labels\Infrastructure\Providers\LabelsServiceProvider;
use Logistics\Network\Infrastructure\Providers\NetworkServiceProvider;
use Logistics\Notifications\Infrastructure\Providers\NotificationsServiceProvider;
use Logistics\Outbox\Infrastructure\Providers\OutboxServiceProvider;
use Logistics\Planning\Infrastructure\Providers\PlanningServiceProvider;
use Logistics\Promise\Infrastructure\Providers\PromiseServiceProvider;
use Logistics\SapConnector\Infrastructure\Providers\SapConnectorServiceProvider;
use Logistics\Settings\Infrastructure\Providers\SettingsServiceProvider;
use Logistics\Shared\Infrastructure\Providers\SharedServiceProvider;
use Logistics\Shipments\Infrastructure\Providers\ShipmentsServiceProvider;
use Logistics\Tracking\Infrastructure\Providers\TrackingServiceProvider;

// Única fuente de verdad del orden de registro: todos los logistics/* están en
// `extra.laravel.dont-discover` del composer.json raíz. Un package nuevo se agrega en
// los DOS lugares en el mismo cambio (bug de doble registro visto en dis-commerce).
//
// OJO: `php artisan filament:install` reescribe este archivo y pierde el orden; revisarlo
// después de correr cualquier instalador.
return [
    AppServiceProvider::class,

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

    // Flujo de un envío
    ShipmentsServiceProvider::class,
    PlanningServiceProvider::class,
    LabelsServiceProvider::class,
    ExecutionServiceProvider::class,

    // Visibilidad
    TrackingServiceProvider::class,
    NotificationsServiceProvider::class,

    // Fase 2 — Prometer: cupos, promesa, conector SAP y transportistas
    CapacityServiceProvider::class,
    PromiseServiceProvider::class,
    SapConnectorServiceProvider::class,
    CarriersServiceProvider::class,

    // Admin web (al final: los resources de cada contexto ya están registrados)
    AdminPanelProvider::class,
];
