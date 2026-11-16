<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbox\InboxRealtimeObserver;
use App\Support\Staff\StaffCity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Logistics\Execution\Infrastructure\Realtime\RouteUpdatedObserver;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Tracking\Infrastructure\Realtime\TrackingUpdatedObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardProductionRealtimeSecret();
        $this->syncStaffCityWithNodeDepartment();
        $this->observeInboxRealtime();
        $this->observeRouteRealtime();
        $this->observeTrackingRealtime();
    }

    /**
     * Candado de seguridad: en PRODUCCIÓN nunca se difunde por sockets con las credenciales públicas de
     * desarrollo. Si el transporte en vivo está activo (`pusher`) pero la clave o el secreto siguen siendo los
     * defaults conocidos (`dismaclocalkey` / `dismaclocalsecret`), apaga el broadcasting (fail-safe: la app
     * sigue en pie y las pantallas vuelven a su refresco normal) y deja un error crítico en el log. Con el
     * secreto por defecto, cualquiera podría firmar canales privados y leer/enviar lo que no le corresponde.
     *
     * Fuera de producción no aplica: dev usa esos defaults a propósito.
     */
    private function guardProductionRealtimeSecret(): void
    {
        $disable = self::realtimeUsesPublicDefaultsInProduction(
            $this->app->environment('production'),
            (string) config('broadcasting.default'),
            (string) config('broadcasting.connections.pusher.key'),
            (string) config('broadcasting.connections.pusher.secret'),
        );

        if (!$disable) {
            return;
        }

        // Fail-safe: sin credenciales propias no se emite nada por sockets (ni se firma ningún canal).
        config(['broadcasting.default' => 'null']);
        Log::critical('Broadcasting deshabilitado en producción: PUSHER_APP_KEY/SECRET son los defaults públicos. Definir credenciales propias para habilitar el tiempo real.');
    }

    /**
     * ¿El tiempo real está activo en producción con las credenciales públicas de desarrollo? Solo entonces se
     * apaga (con el secreto por defecto, cualquiera firmaría canales privados). Predicado puro, para poder
     * probarlo sin arrancar la aplicación.
     */
    public static function realtimeUsesPublicDefaultsInProduction(bool $isProduction, string $default, string $key, string $secret): bool
    {
        return $isProduction
            && $default === 'pusher'
            && ($key === 'dismaclocalkey' || $secret === 'dismaclocalsecret');
    }

    /**
     * Tiempo real de la bandeja interna: las alertas de flota y los documentos por vencer avisan a la
     * oficina por sockets. Se suma como observador del outbox (nunca altera el despacho ni los reintentos).
     */
    private function observeInboxRealtime(): void
    {
        $this->addOutboxObserver(InboxRealtimeObserver::class);
    }

    /**
     * Tiempo real de la hoja de ruta del chofer: cada hecho que la cambia (parada entregada o fallida,
     * paradas sumadas o quitadas, ruta iniciada o cerrada, cambio de camión) avisa `route.updated` al
     * chofer y a sus ayudantes por su canal personal.
     */
    private function observeRouteRealtime(): void
    {
        $this->addOutboxObserver(RouteUpdatedObserver::class);
    }

    /**
     * Tiempo real del seguimiento del pedido: cada cambio de estado visible (incluidos cancelado,
     * reprogramado y devuelto), el avance por producto y la ruta que lo transporta avisan `tracking.updated`
     * por el canal del pedido y el tema `tracking` de su ciudad.
     */
    private function observeTrackingRealtime(): void
    {
        $this->addOutboxObserver(TrackingUpdatedObserver::class);
    }

    /**
     * Suma un observador del outbox si todavía no está (nunca altera el despacho ni los reintentos).
     *
     * @param class-string $observer
     */
    private function addOutboxObserver(string $observer): void
    {
        $observers = array_values((array) config('logistics-outbox.observers', []));
        if (!in_array($observer, $observers, true)) {
            $observers[] = $observer;
            config(['logistics-outbox.observers' => $observers]);
        }
    }

    /**
     * Si cambia el departamento de un nodo, su personal pasa a ser de esa ciudad (regla: quien pertenece a
     * un nodo es de la ciudad de ese nodo). Se engancha acá, en el anfitrión, para que el contexto de red
     * no conozca la tabla `users`. Si el nodo queda sin departamento, la ciudad del personal se conserva.
     */
    private function syncStaffCityWithNodeDepartment(): void
    {
        NodeRecord::saved(static function (NodeRecord $node): void {
            if (!$node->wasChanged('department')) {
                return;
            }

            $city = StaffCity::code($node->department);
            if ($city === null) {
                return;
            }

            DB::table('users')
                ->where('node_id', $node->id)
                ->where(static function ($query) use ($city): void {
                    $query->whereNull('city')->orWhere('city', '!=', $city);
                })
                ->update(['city' => $city, 'updated_at' => now()]);
        });
    }
}
