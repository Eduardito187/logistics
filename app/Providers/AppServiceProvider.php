<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbox\InboxRealtimeObserver;
use App\Support\Staff\StaffCity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

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
        $this->syncStaffCityWithNodeDepartment();
        $this->observeInboxRealtime();
    }

    /**
     * Tiempo real de la bandeja interna: las alertas de flota y los documentos por vencer avisan a la
     * oficina por sockets. Se suma como observador del outbox (nunca altera el despacho ni los reintentos).
     */
    private function observeInboxRealtime(): void
    {
        $observers = array_values((array) config('logistics-outbox.observers', []));
        if (!in_array(InboxRealtimeObserver::class, $observers, true)) {
            $observers[] = InboxRealtimeObserver::class;
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
