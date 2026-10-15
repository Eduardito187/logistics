<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Widgets\AutoPlanRunsWidget;
use App\Filament\Widgets\DeliveriesTodayWidget;
use App\Filament\Widgets\FailedDeliveriesTodayWidget;
use App\Filament\Widgets\LicensesDocumentsExpiringWidget;
use App\Filament\Widgets\NotificationsTodayWidget;
use App\Filament\Widgets\OutboxHealthWidget;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Filament\Widgets\QueueHealthWidget;
use App\Filament\Widgets\UnacknowledgedTelemetryAlertsWidget;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Torre de control: el landing operativo del admin. Reemplaza el dashboard genérico de Filament por
 * una sala de operaciones que responde "¿qué requiere acción AHORA?".
 *
 * El orden de los widgets cuenta una historia operativa de arriba hacia abajo:
 *   1. EXCEPCIONES / acciones  — lo que necesita que alguien actúe ya (entregas fallidas de hoy,
 *      alertas de telemetría sin reconocer, licencias/documentos por vencer).
 *   2. SALUD del tubo          — el latido del I/O externo y las colas (outbox, workers).
 *   3. KPIs del día y globales — entregas, notificaciones, corridas del planificador y totales.
 *
 * Es solo presentación: cada widget lee por sus propios medios tolerantes ({@see ReadsControlTowerData})
 * y despacha acciones por el CommandBus; esta página solo los ordena y gatea por rol.
 */
final class ControlTower extends Dashboard
{
    // Home del panel: misma ruta que el dashboard por defecto para ser el primer destino tras el login.
    protected static string $routePath = '/';

    // Icono operativo (heroicons v2): señal/monitoreo, no un dashboard genérico.
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-signal';

    public static function getNavigationLabel(): string
    {
        return __('Control Tower');
    }

    public function getTitle(): string
    {
        return __('Control Tower');
    }

    public function getHeading(): string
    {
        return __('Control Tower');
    }

    public function getSubheading(): string
    {
        return __('What needs action right now.');
    }

    /**
     * Visible solo para cuentas con alcance operativo global (administrador o supervisor), mismo
     * criterio que los widgets ({@see ReadsControlTowerData::canView()}). El panel ya gatea a estos
     * dos roles; esto es defensa en profundidad para que la página nunca sea el landing de otro rol.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Dos columnas en pantallas anchas; los widgets de ancho completo igual ocupan toda la fila, así
     * que el grid solo afecta a widgets angostos que se añadan en el futuro.
     */
    public function getColumns(): int
    {
        return 2;
    }

    /**
     * Orden operativo explícito (excepciones -> salud -> KPIs). Se declara aquí en vez de depender del
     * auto-descubrimiento para que la narrativa no dependa del orden de registro del panel.
     *
     * @return array<int, class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            // 1. Excepciones / acciones: lo primero que debe ver operaciones.
            FailedDeliveriesTodayWidget::class,
            UnacknowledgedTelemetryAlertsWidget::class,
            LicensesDocumentsExpiringWidget::class,

            // 2. Salud del tubo transaccional (outbox + colas).
            OutboxHealthWidget::class,
            QueueHealthWidget::class,

            // 3. KPIs del día y globales.
            DeliveriesTodayWidget::class,
            NotificationsTodayWidget::class,
            AutoPlanRunsWidget::class,
            PlatformStatsWidget::class,
        ];
    }
}
