<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Directorio de clientes (Fase 4 CRM): la puerta de entrada al Cliente 360. Lista los clientes de la
 * plataforma (derivados de sus envíos) con su actividad, una búsqueda por nombre/teléfono/documento, y un
 * enlace a la ficha 360 de cada uno ({@see CustomerProfile}). SOLO lectura y tolerante.
 *
 * Es página cockpit (capa `.distrack-cockpit`, español directo) y de alcance de oficina. Vive en el grupo
 * Comercio. No expone UUIDs: la referencia del cliente es su nombre y sus datos de contacto.
 */
final class CustomersDirectory extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.customers-directory';

    /** Texto de búsqueda (nombre, teléfono o documento). */
    public string $search = '';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationLabel(): string
    {
        return 'Clientes';
    }

    public function getTitle(): string
    {
        return 'Clientes';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Clientes (derivados de los envíos) con su conteo y última actividad, filtrados por la búsqueda.
     * Más recientes primero. Acotado para no cargar de más.
     *
     * @return list<array{name: string, shipments: int, phone: ?string, last: ?string}>
     */
    #[Computed]
    public function customers(): array
    {
        try {
            $query = DB::table('shipments')
                ->whereNotNull('customer_name')
                ->where('customer_name', '!=', '');

            $term = trim($this->search);
            if ($term !== '') {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('customer_name', 'ilike', $like)
                        ->orWhere('customer_phone', 'ilike', $like)
                        ->orWhere('customer_document_id', 'ilike', $like);
                });
            }

            $rows = $query->groupBy('customer_name')
                ->orderByRaw('max(created_at) desc')
                ->limit(60)
                ->get([
                    'customer_name',
                    DB::raw('count(*) as shipments'),
                    DB::raw('max(customer_phone) as phone'),
                    DB::raw('max(created_at) as last'),
                ]);

            $out = [];
            foreach ($rows as $row) {
                $last = is_string($row->last) ? $row->last : null;
                $out[] = [
                    'name' => (string) $row->customer_name,
                    'shipments' => (int) $row->shipments,
                    'phone' => is_string($row->phone) && $row->phone !== '' ? $row->phone : null,
                    'last' => $this->shortDate($last),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** URL de la ficha 360 de un cliente. */
    public function profileUrl(string $name): string
    {
        return CustomerProfile::urlFor($name);
    }

    /** Fecha legible corta, tolerante. */
    private function shortDate(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM YYYY');
        } catch (Throwable) {
            return null;
        }
    }
}
