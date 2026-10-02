<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Logistics\Coverage\Infrastructure\Providers\CoverageServiceProvider;
use Logistics\Execution\Infrastructure\Providers\ExecutionServiceProvider;
use Logistics\Fleet\Infrastructure\Providers\FleetServiceProvider;
use Logistics\Labels\Infrastructure\Providers\LabelsServiceProvider;
use Logistics\Network\Infrastructure\Providers\NetworkServiceProvider;
use Logistics\Notifications\Infrastructure\Providers\NotificationsServiceProvider;
use Logistics\Planning\Infrastructure\Providers\PlanningServiceProvider;
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

    // Admin web (al final: los resources de cada contexto ya están registrados)
    AdminPanelProvider::class,
];
