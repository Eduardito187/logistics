<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App web (Inertia) — panel operativo de inicio. Métricas rápidas de solo lectura. Tolera tablas
 * ausentes (cuenta 0) para no romper el panel en entornos a medio sembrar.
 */
final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                ['label' => 'Pedidos', 'value' => $this->count('shipments'), 'hint' => 'Total registrados'],
                ['label' => 'Rutas', 'value' => $this->count('execution_driver_routes'), 'hint' => 'De reparto'],
                ['label' => 'Entregas en tránsito', 'value' => $this->count('execution_deliveries', ['status' => 'IN_TRANSIT'])],
                ['label' => 'Seguimientos', 'value' => $this->count('tracking_shipments'), 'hint' => 'Activos'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $where
     */
    private function count(string $table, array $where = []): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }
}
