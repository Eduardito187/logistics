<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Directorio de SOCIOS COMERCIALES (CRM): transportistas y vendedores son terceros externos de la
 * plataforma; se ven y se gestionan juntos —como socios— en un solo lugar, con su tipo, documento (NIT),
 * estado y contacto, enlazando a la ficha 360 de cada uno (CarrierHub / SellerHub). SOLO lectura,
 * tolerante; sin UUID visible. Página cockpit (`.distrack-cockpit`, español directo), alcance de oficina.
 */
final class PartnersDirectory extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.partners-directory';

    /** Búsqueda por nombre o documento (NIT). */
    public string $search = '';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationLabel(): string
    {
        return 'Socios comerciales';
    }

    public function getTitle(): string
    {
        return 'Socios comerciales';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Todos los socios (transportistas + vendedores), con tipo, documento, estado, contacto y enlace a su
     * ficha. Ordenados por nombre. Filtrados por la búsqueda.
     *
     * @return list<array{name: string, type: string, tone: string, document: ?string, contact: ?string, active: bool, url: ?string}>
     */
    #[Computed]
    public function partners(): array
    {
        $term = trim($this->search);
        $out = [];

        foreach ($this->carriers($term) as $r) {
            $out[] = $r;
        }
        foreach ($this->sellers($term) as $r) {
            $out[] = $r;
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Conteos por tipo para la tira de KPIs.
     *
     * @return array{carriers: int, sellers: int}
     */
    #[Computed]
    public function counts(): array
    {
        try {
            return [
                'carriers' => DB::table('carriers')->count(),
                'sellers' => DB::table('sellers')->count(),
            ];
        } catch (Throwable) {
            return ['carriers' => 0, 'sellers' => 0];
        }
    }

    /**
     * @return list<array{name: string, type: string, tone: string, document: ?string, contact: ?string, active: bool, url: ?string}>
     */
    private function carriers(string $term): array
    {
        try {
            $q = DB::table('carriers');
            if ($term !== '') {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like));
            }

            $out = [];
            foreach ($q->orderBy('name')->limit(200)->get(['id', 'name', 'tax_id', 'status']) as $r) {
                $out[] = [
                    'name' => (string) $r->name,
                    'type' => 'Transportista',
                    'tone' => 'agent',
                    'document' => $this->str($r->tax_id),
                    'contact' => null,
                    'active' => strtolower((string) $r->status) === 'active',
                    'url' => $this->url(CarrierResource::class, (string) $r->id),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{name: string, type: string, tone: string, document: ?string, contact: ?string, active: bool, url: ?string}>
     */
    private function sellers(string $term): array
    {
        try {
            $q = DB::table('sellers');
            if ($term !== '') {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('legal_name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like)->orWhere('contact_name', 'ilike', $like));
            }

            $out = [];
            foreach ($q->orderBy('legal_name')->limit(200)->get(['id', 'legal_name', 'tax_id', 'status', 'contact_name', 'contact_phone']) as $r) {
                $contact = $this->str($r->contact_name) ?? $this->str($r->contact_phone);
                $out[] = [
                    'name' => (string) $r->legal_name,
                    'type' => 'Vendedor',
                    'tone' => 'positive',
                    'document' => $this->str($r->tax_id),
                    'contact' => $contact,
                    'active' => strtolower((string) $r->status) === 'active',
                    'url' => $this->url(SellerResource::class, (string) $r->id),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Enlace tolerante a la ficha (view → edit → index). @param class-string<\Filament\Resources\Resource> $resource */
    private function url(string $resource, string $id): ?string
    {
        foreach (['view', 'edit'] as $page) {
            try {
                return $resource::getUrl($page, ['record' => $id]);
            } catch (Throwable) {
                // intenta la siguiente página
            }
        }

        try {
            return $resource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }

    /** Patrón ILIKE escapado. */
    private function like(string $term): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
    }

    /** Normaliza a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
