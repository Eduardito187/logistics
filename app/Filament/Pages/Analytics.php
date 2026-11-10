<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Cockpit\AnalyticsBoard;
use App\Support\Cockpit\CockpitPulse;
use App\Support\Cockpit\CockpitTrends;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Analítica de operaciones (Fase 4 — "datos que se ven"): una sola pantalla con los cortes agregados que
 * importan para dirigir la operación — pulso de KPIs con su delta, tendencias diarias de recibidos y
 * entregados, carga por estado, efectividad de entrega y concentración de demanda por nodo destino.
 *
 * Es una página de COCKPIT (capa `.distrack-cockpit`): compone solo lectura desde los servicios del
 * cockpit (tolerantes y cacheados) y no toca dominio ni infraestructura. Alcance de oficina
 * (administrador/supervisor) vía {@see OfficeOnly}. Español directo (excepción cockpit del proyecto);
 * nunca muestra UUIDs (los nodos salen por su nombre).
 */
final class Analytics extends Page
{
    // Alcance operativo global: administrador y supervisor (igual que el resto del backoffice).
    use OfficeOnly;

    // Icono de analítica (heroicons v2).
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    // Cerca de la Torre de Control dentro del grupo Operaciones.
    protected static ?int $navigationSort = -1;

    // Vista propia del cockpit.
    protected string $view = 'filament.pages.analytics';

    public static function getNavigationGroup(): string
    {
        // Clave existente en es.json; agrupa junto al resto de Operaciones.
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'Analítica';
    }

    public function getTitle(): string
    {
        return 'Analítica de operaciones';
    }

    // El encabezado del cockpit hace de título; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Mosaicos del pulso operativo (con delta contra el snapshot anterior).
     *
     * @return list<array{key: string, label: string, value: int, delta: string|null, trend: string, tone: string, delta_tone: string, hint: string|null}>
     */
    #[Computed]
    public function pulse(): array
    {
        return app(CockpitPulse::class)->metrics();
    }

    /**
     * Tendencias diarias y carga por estado para los gráficos.
     *
     * @return array{received: array{labels: list<string>, values: list<int>}, delivered: array{labels: list<string>, values: list<int>}, status: list<array{label: string, count: int, tone: string}>, totals: array{received: int, delivered: int}}
     */
    #[Computed]
    public function trends(): array
    {
        $trends = app(CockpitTrends::class);

        return [
            'received' => $trends->receivedPerDay(),
            'delivered' => $trends->deliveredPerDay(),
            'status' => $trends->statusDistribution(),
            'totals' => $trends->totals(),
        ];
    }

    /**
     * Efectividad de entrega sobre entregas cerradas.
     *
     * @return array{delivered: int, failed: int, closed: int, pct: int|null}
     */
    #[Computed]
    public function effectiveness(): array
    {
        return app(AnalyticsBoard::class)->effectiveness();
    }

    /**
     * Nodos destino con más envíos (nombre legible + conteo).
     *
     * @return list<array{label: string, count: int}>
     */
    #[Computed]
    public function topDestinations(): array
    {
        return app(AnalyticsBoard::class)->topDestinations();
    }
}
