<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Cockpit\AttentionQueues;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * "Necesita atención": tablero de triage del admin. Junta en una pantalla las colas de trabajo
 * accionables (envíos atascados, entregas fallidas sin reprogramar, pedidos pagados sin envío y
 * documentos de flota vencidos/por vencer) para que la oficina vea de un vistazo qué está trabado y
 * entre a resolverlo. Cada fila enlaza al hub del recurso que YA tiene la acción (no muta acá).
 *
 * Alcance de oficina (administrador/supervisor) vía {@see OfficeOnly}. Página propia del cockpit
 * (capa `.distrack-cockpit`), español directo como el resto de la Torre de control. Datos reales y
 * tolerantes vía {@see AttentionQueues}; si una fuente falta, su cola degrada a vacío.
 */
final class NeedsAttention extends Page
{
    use OfficeOnly;

    // Bandeja / triage (heroicons v2).
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox-stack';

    // Junto a la Torre de control, dentro de Operaciones.
    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.needs-attention';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'Necesita atención';
    }

    public function getTitle(): string
    {
        return 'Necesita atención';
    }

    // El cockpit hace de encabezado; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Las cuatro colas de atención, memoizadas durante el request de Livewire.
     *
     * @return array<string, array{count: int, items: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function queues(): array
    {
        return app(AttentionQueues::class)->all();
    }
}
