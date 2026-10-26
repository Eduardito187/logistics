<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Avatars\LocalAvatarProvider;
use App\Filament\Pages\Auth\RequestPasswordOtp;
use App\Http\Middleware\SecurityHeaders;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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
use Logistics\Execution\Presentation\Filament\Pages\LastMileBoard;
use Logistics\Fleet\Presentation\Filament\Pages\DriverHistory;
use Logistics\FleetOps\Presentation\Filament\Pages\FleetCostDashboard;
use Logistics\Inbound\Presentation\Filament\Pages\InboundBoard;
use Logistics\Inbound\Presentation\Filament\Pages\ReceivingCalendar;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;

/**
 * Admin web de la plataforma: datos maestros, reglas, flota, reconciliación.
 *
 * Cada texto visible va en inglés (misma convención que el admin de dis-commerce).
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
            // Página de cuenta del usuario: aquí vive la administración del MFA (alta/baja de la
            // autenticación por app, regeneración de códigos de recuperación). Sin página de perfil
            // el usuario no tendría dónde activar su 2FA opcional.
            ->profile()
            // MFA NATIVO de Filament v4. Usamos "App authentication" (TOTP): el flujo de alta muestra
            // el QR ESCANEABLE y además la CLAVE/secreto en texto copiable (lo pide el dueño), junto
            // con los códigos de recuperación (->recoverable()). El modelo User implementa
            // HasAppAuthentication + HasAppAuthenticationRecovery (lo provee el agente CORE).
            //
            // Renderizado del QR: no requiere dependencia extra. filament/filament ya exige
            // chillerlan/php-qrcode (^5.0) como dependencia dura, así que el QR se genera como SVG
            // inline sin necesitar bacon/bacon-qr-code ni la extensión imagick.
            //
            // El MFA queda OPCIONAL por usuario (tercer argumento isRequired = false, el valor por
            // defecto): cada quien decide si lo activa desde su perfil. Para FORZARLO en el panel completo
            // basta pasar `isRequired: true` aquí (o un Closure configurable); Filament entonces obliga
            // a configurarlo tras el login. No se incluye Email authentication: requeriría que User
            // implemente HasEmailAuthentication, fuera del alcance de este cambio.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ], isRequired: false)
            // Reseteo de contraseña por CÓDIGO OTP (no por enlace). Registramos solo la página de
            // solicitud, que es un flujo de dos pasos en una sola ruta (ver RequestPasswordOtp):
            // evita la ruta `password-reset/reset` que Filament fuerza con middleware `signed`.
            // Con esto el login muestra automáticamente el enlace "Forgot password?".
            ->passwordReset(RequestPasswordOtp::class)
            // Modo SPA: navegación sin recargar la página (wire:navigate) + barra de progreso superior,
            // para que el admin se sienta una app web. Los mapas Leaflet cargan vía @assets (persistente
            // entre navegaciones SPA), así que siguen funcionando.
            ->spa()
            // Marca del panel interno: DisTrack ("DE ANDÉN A PUERTA."). Es una identidad propia del
            // panel operativo, distinta de la app de clientes (blanca/roja), para no confundir audiencias.
            ->brandName('DisTrack')
            // Logo de cabecera: versión para fondos claros y, en modo oscuro (tema Noche), la inversa.
            // Se referencian por URL del kit servido en public/brand/distrack (nunca SVG inline).
            ->brandLogo(asset('brand/distrack/header-logo.svg'))
            ->darkModeBrandLogo(asset('brand/distrack/header-logo-inverse.svg'))
            ->brandLogoHeight('30px')
            // Favicon SVG del kit (los PNG de respaldo y el resto de <head> van en el partial distrack-head).
            ->favicon(asset('brand/distrack/favicon.svg'))
            // Panel interno en tema Noche (#141414) por defecto para diferenciarlo de la app de clientes.
            // Se mantiene el toggle de modo claro/oscuro de Filament (no se fuerza), solo cambia el default.
            ->defaultThemeMode(ThemeMode::Dark)
            // Avatar por defecto generado localmente (iniciales en SVG data URI). El proveedor de
            // Filament por defecto sale a ui-avatars.com, que offline o con la CSP del admin cerrada
            // deja la imagen rota en la barra superior. Con este proveedor nunca depende de internet.
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            // Tipografía de marca DisTrack: Barlow (texto/botones/wordmark). Se AUTO-HOSPEDA (woff2 OFL
            // bajo 'self') vía LocalFontProvider desde un CSS propio, para NO salir a Google Fonts/bunny
            // (respeta la CSP cerrada del admin y ADR 013 "sin Google"). Las familias Barlow Condensed
            // (títulos) e IBM Plex Mono (códigos) se declaran en el tema (theme.css), también locales.
            ->font('Barlow', asset('brand/distrack/fonts/barlow.css'), LocalFontProvider::class)
            // Tema compilado propio (Tailwind v4) que escanea nuestras vistas para que sus clases apliquen.
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Paleta de marca DisTrack: Rojo #E30613 como primario (marca/acción), gris neutro del panel.
            ->colors([
                'primary' => Color::hex('#E30613'),
                'danger' => Color::hex('#D32F2F'),
                'gray' => Color::Neutral,
            ])
            // Acentos finos del tema (foco, tarjetas): CSS inyectado en <head>.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.brand-theme')->render(),
            )
            // Íconos (favicon SVG + PNG de respaldo, apple-touch-icon), manifest, theme-color y metadatos
            // Open Graph del panel DisTrack. Partial propio; referencia los assets por URL del kit.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.distrack-head')->render(),
            )
            // Arquitectura de información "operaciones-primero": 5 grupos por modelo mental del operador
            // en vez de un grupo por bounded context (antes 16). El orden sigue el flujo del negocio:
            // Operations (flujo vivo) → Fleet (camiones y personas) → Network (grafo físico) →
            // Commerce (pedidos y dinero) → Administration (config y gobierno, solo admin).
            // Cada Resource/Page declara su grupo y orden con getNavigationGroup()/getNavigationSort().
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Fleet'),
                NavigationGroup::make('Network'),
                NavigationGroup::make('Commerce'),
                NavigationGroup::make('Administration'),
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
            ->discoverResources(
                in: base_path('packages/logistics/fleetops/src/Presentation/Filament'),
                for: 'Logistics\FleetOps\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/sellers/src/Presentation/Filament'),
                for: 'Logistics\Sellers\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/inbound/src/Presentation/Filament'),
                for: 'Logistics\Inbound\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/inventory/src/Presentation/Filament'),
                for: 'Logistics\Inventory\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/settlement/src/Presentation/Filament'),
                for: 'Logistics\Settlement\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/logistics/returns/src/Presentation/Filament'),
                for: 'Logistics\Returns\Presentation\Filament',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                // Landing del panel: la Torre de control (App\Filament\Pages\ControlTower, ruta '/') se
                // auto-descubre con discoverPages y reemplaza al Dashboard genérico de Filament, que ya no
                // se registra. Es la sala de operaciones: excepciones y acciones primero, KPIs después.
                //
                // Cola de última milla / despacho del contexto Execution: entregas del día accionables.
                LastMileBoard::class,
                // Recepción de proveedores (marketplace, ADR 017): tablero de ASNs del día y calendario de andenes.
                InboundBoard::class,
                ReceivingCalendar::class,
                // Calendario de despacho del contexto Capacity (cupos por día/franja, por nodo/zona).
                DispatchCalendar::class,
                // Mapa de flota en vivo (telemetría GPS): todos los camiones en tiempo real.
                LiveFleetMap::class,
                // Tablero de costos de flota propia (TCO, costo/km, mantenimiento vencido) del contexto FleetOps.
                FleetCostDashboard::class,
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
