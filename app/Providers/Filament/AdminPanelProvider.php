<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\Role;
use App\Filament\Avatars\LocalAvatarProvider;
use App\Filament\Pages\Auth\RequestPasswordOtp;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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
use Logistics\Labels\Presentation\Filament\Pages\LabelFormatSettings;
use Logistics\Labels\Presentation\Filament\Pages\ScanLabelPage;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;

/**
 * Admin web de la plataforma: datos maestros, reglas, flota, reconciliación.
 *
 * Cada texto visible va en inglés (misma convención que el admin de dis-commerce).
 * El tablero de planificación y la torre de control serán páginas React aparte (ADR 005).
 */
final class AdminPanelProvider extends PanelProvider
{
    /**
     * ¿La cuenta autenticada tiene alcance de OFICINA (administrador o supervisor)? Gatea el command
     * palette y otras superficies globales del backoffice. El administrador tiene acceso a todo.
     */
    private static function hasOfficeScope(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

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
            // Ancho COMPLETO en el panel entero (todas las vistas usan toda la pantalla, sin la columna
            // centrada por defecto). Cada página puede sobreescribirlo con getMaxContentWidth().
            ->maxContentWidth(Width::Full)
            // Un SOLO buscador global: el command palette ⌘K (más abajo, render hook). Se APAGA el buscador
            // propio de Filament para no tener dos inputs de búsqueda en el header.
            ->globalSearch(false)
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
            // Tiempo real (Fase 6): Laravel Echo + Pusher contra Soketi self-host, para el push en vivo de
            // posiciones de flota a la Torre de Control. Solo oficina (quien ve el mapa). Env-gated en el
            // cliente: si no hay VITE_PUSHER_APP_KEY, no conecta y queda el polling como fallback.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => self::hasOfficeScope() ? view('filament.realtime-echo')->render() : '',
            )
            // Command palette global (⌘K) de DisTrack OS: búsqueda transversal de entidades-eje y salto
            // directo, sin volver al menú. Disparador en el topbar; palette montado una vez al final del
            // body. Solo alcance de OFICINA (admin/supervisor) — el admin accede a todo; los roles de campo
            // usan su UI mínima y no lo ven.
            // ORDEN del header (izquierda → derecha, dentro del clúster derecho `.fi-topbar-end`):
            //   buscador · escanear · notificaciones · PERFIL (al final, a la derecha del todo).
            // Se inyectan en GLOBAL_SEARCH_BEFORE (DENTRO de `.fi-topbar-end`, ANTES del menú de usuario), no
            // en TOPBAR_END (que queda FUERA, a la derecha del perfil). El orden de registro = orden visual.
            // 1) Buscador (command palette ⌘K): el más a la izquierda del grupo.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => self::hasOfficeScope() ? view('filament.cockpit.search-trigger')->render() : '',
            )
            // 2) Escanear etiquetas (cámara): acceso directo al escáner desde el header, fácil de hallar.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => self::hasOfficeScope() ? view('filament.cockpit.scan-trigger')->render() : '',
            )
            // 3) Notificaciones (campana): alertas de telemetría + documentos por vencer, unificadas y
            // accionables. Reemplaza "Alertas" como ítem suelto del menú. Queda pegada al perfil, a su izquierda.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => self::hasOfficeScope() ? view('filament.cockpit.notifications')->render() : '',
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => self::hasOfficeScope() ? view('filament.cockpit.palette-mount')->render() : '',
            )
            // Arquitectura de información "operaciones-primero": 5 grupos por modelo mental del operador
            // en vez de un grupo por bounded context (antes 16). El orden sigue el flujo del negocio:
            // Operations (flujo vivo) → Fleet (camiones y personas) → Network (grafo físico) →
            // Orden de los grupos (modelo mental del operador, no por bounded context): Comercio (el
            // negocio) → Clientes y experiencia (voz del cliente) → Operaciones (flujo físico) → Flota →
            // Red → Configuración (plataforma, al final). Cada Resource/Page declara su grupo y orden con
            // getNavigationGroup()/getNavigationSort(); dentro de cada grupo el orden va de tableros (sort
            // bajo) a registros a catálogo/ajustes (sort alto).
            // IMPORTANTE: la etiqueta de cada NavigationGroup debe COINCIDIR con la cadena que devuelve
            // getNavigationGroup() de los recursos (que es la traducida vía __()), si no, Filament no asocia
            // el grupo y pierde el orden. Por eso se traducen aquí con __().
            // Grupos COLAPSADOS por defecto (CRM, no CRUD): el menú abre compacto —la Torre de control y los
            // encabezados de grupo— y cada grupo se expande al entrar a él; Filament recuerda el estado. Así
            // el operador no ve un volcado plano de todos los recursos, sino secciones que abre según su tarea.
            ->navigationGroups([
                NavigationGroup::make(__('Commerce'))->collapsed(),
                NavigationGroup::make(__('Customer experience'))->collapsed(),
                NavigationGroup::make(__('Operations'))->collapsed(),
                NavigationGroup::make(__('Fleet'))->collapsed(),
                // Personal: choferes, ayudantes y usuarios son PERSONAS (con roles distintos); se gestionan
                // en una sola sección, con un directorio unificado. El dominio sigue separado (Driver/Helper/
                // User son agregados distintos); lo que se unifica es la experiencia del admin.
                NavigationGroup::make(__('Personnel'))->collapsed(),
                NavigationGroup::make(__('Network'))->collapsed(),
                NavigationGroup::make(__('Administration'))->collapsed(),
                // Config técnica agrupada aparte para aligerar «Configuración»: integraciones (SAP, tokens,
                // conectores) y mensajería (plantillas, reglas de cascada, log de notificaciones).
                NavigationGroup::make(__('Integrations'))->collapsed(),
                NavigationGroup::make(__('Messaging'))->collapsed(),
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
            // Comercio (ADR 018): Resources del frente comercial bajo packages/commerce/<ctx>.
            ->discoverResources(
                in: base_path('packages/commerce/catalog/src/Presentation/Filament'),
                for: 'Commerce\Catalog\Presentation\Filament',
            )
            ->discoverResources(
                in: base_path('packages/commerce/orders/src/Presentation/Filament'),
                for: 'Commerce\Orders\Presentation\Filament',
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
                // Ajuste del formato (simbología) de las etiquetas: QR, Zebra o ambos (contexto Labels).
                LabelFormatSettings::class,
                // Escáner web de etiquetas (cámara + BarcodeDetector): lee QR o Zebra, resuelve el bulto.
                ScanLabelPage::class,
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
