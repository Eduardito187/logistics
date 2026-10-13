<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Logistics\Capacity\Presentation\Filament\Pages\DispatchCalendar;
use Logistics\Fleet\Presentation\Filament\Pages\DriverHistory;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;

/**
 * Admin web de la plataforma: datos maestros, reglas, flota, reconciliación.
 *
 * Todo texto visible va en inglés (misma convención que el admin de dis-commerce).
 * El tablero de planificación y la torre de control serán páginas React aparte (ADR 005).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Dismac Logistics')
            // Tipografía del e-commerce Dismatec.
            ->font('Open Sans')
            // Tema compilado propio (Tailwind v4) que escanea nuestras vistas para que sus clases apliquen.
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Paleta de marca Dismac (del e-commerce): rojo #E2231A como primario, gris neutro del panel.
            ->colors([
                'primary' => Color::hex('#E2231A'),
                'danger' => Color::hex('#D32F2F'),
                'gray' => Color::Neutral,
            ])
            // Tema visual de marca Dismac (barra superior roja, fondo claro, acentos): CSS inyectado en <head>.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.brand-theme')->render(),
            )
            // Orden de los grupos de navegación: sigue el flujo operativo (datos maestros →
            // ejecución → integraciones → plataforma). Cada Resource declara su propio grupo.
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Network'),
                NavigationGroup::make('Fleet'),
                NavigationGroup::make('Shipments'),
                NavigationGroup::make('Capacity'),
                NavigationGroup::make('Promise'),
                NavigationGroup::make('Carriers'),
                NavigationGroup::make('Execution'),
                NavigationGroup::make('Tracking'),
                NavigationGroup::make('Telemetry'),
                NavigationGroup::make('Labels'),
                NavigationGroup::make('Notifications'),
                NavigationGroup::make('Integrations'),
                NavigationGroup::make('Access'),
                NavigationGroup::make('Platform'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // Resources de cada bounded context: viven en packages/logistics/<ctx>/src/Presentation/Filament
            // con namespace Logistics\<Ctx>\Presentation\Filament. Filament deriva el sub-namespace
            // (Resources\, Resource\, o plano) del subdirectorio, así que apuntamos a la raíz Filament de cada package.
            ->discoverResources(
                in: base_path('packages/logistics/network/src/Presentation/Filament'),
                for: 'Logistics\Network\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/coverage/src/Presentation/Filament'),
                for: 'Logistics\Coverage\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/fleet/src/Presentation/Filament'),
                for: 'Logistics\Fleet\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/shipments/src/Presentation/Filament'),
                for: 'Logistics\Shipments\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/planning/src/Presentation/Filament'),
                for: 'Logistics\Planning\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/capacity/src/Presentation/Filament'),
                for: 'Logistics\Capacity\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/promise/src/Presentation/Filament'),
                for: 'Logistics\Promise\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/carriers/src/Presentation/Filament'),
                for: 'Logistics\Carriers\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/execution/src/Presentation/Filament'),
                for: 'Logistics\Execution\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/tracking/src/Presentation/Filament'),
                for: 'Logistics\Tracking\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/labels/src/Presentation/Filament'),
                for: 'Logistics\Labels\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/notifications/src/Presentation/Filament'),
                for: 'Logistics\Notifications\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/sap-connector/src/Presentation/Filament'),
                for: 'Logistics\SapConnector\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/outbox/src/Presentation/Filament'),
                for: 'Logistics\Outbox\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/settings/src/Presentation/Filament'),
                for: 'Logistics\Settings\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/access/src/Presentation/Filament'),
                for: 'Logistics\Access\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/telemetry/src/Presentation/Filament'),
                for: 'Logistics\Telemetry\Presentation\Filament',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                // Calendario de despacho del contexto Capacity (cupos por día/franja, por nodo/zona).
                DispatchCalendar::class,
                // Mapa de flota en vivo (telemetría GPS): todos los camiones en tiempo real.
                LiveFleetMap::class,
                // Historial operativo del chofer (desempeño cuantificable) del contexto Fleet.
                DriverHistory::class,
            ])
            // Los widgets del tablero (p. ej. PlatformStatsWidget, validado por rol) se descubren
            // desde app/Filament/Widgets.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                // Cabeceras de seguridad del admin (S-12): CSP (Leaflet/Geoman/unpkg + tiles OSM),
                // HSTS, X-Frame-Options, Referrer-Policy. Primero, para que cubra toda respuesta del panel.
                SecurityHeaders::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // NOTA: NO incluir AuthenticateSession aquí. Cierra la sesión cuando el hash de
                // contraseña de la sesión no coincide, y al re-autenticar por la cookie "recordar" la
                // sesión nueva no trae ese hash → logout aleatorio, "recordar" inútil, y si ocurre a
                // mitad de una acción Livewire deja la URL "intended" rota (404 al volver). El panel
                // por defecto de Filament no lo trae; se mantiene fuera a propósito.
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
