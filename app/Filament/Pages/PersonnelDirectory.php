<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\StoreUserResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Access\Presentation\Filament\Resources\UserResource;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Directorio unificado de PERSONAS (CRM): choferes, ayudantes y usuarios son personas con roles distintos;
 * acá se ven y se gestionan como lo que son —personas— en un solo lugar, con su tipo, documento, contacto,
 * estado y un enlace a su ficha. El DOMINIO sigue separado (Driver/Helper/User son agregados distintos con
 * sus reglas); lo que se unifica es la EXPERIENCIA del admin. SOLO lectura y tolerante; sin UUID visible.
 *
 * Página cockpit (capa `.distrack-cockpit`, español directo), alcance de oficina, grupo Personal.
 */
final class PersonnelDirectory extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.personnel-directory';

    /** Texto de búsqueda (nombre, documento, teléfono o email). */
    public string $search = '';

    public static function getNavigationGroup(): string
    {
        return __('Personnel');
    }

    public static function getNavigationLabel(): string
    {
        return 'Directorio';
    }

    public function getTitle(): string
    {
        return 'Directorio de personas';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Accesos «gestionar» a las listas CRUD de cada rol, que ya NO viven en el menú (su hogar es este
     * Directorio). Así cada recurso demotado sigue alcanzable desde su sección de pertenencia, sin
     * ensuciar la navegación con un ítem por rol. Mismo patrón «avanzado» del calendario y el mapa.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageDrivers')->label('Choferes')->icon('heroicon-o-identification')->color('gray')->url(DriverResource::getUrl('index')),
            Action::make('manageHelpers')->label('Ayudantes')->icon('heroicon-o-user-group')->color('gray')->url(HelperResource::getUrl('index')),
            Action::make('manageUsers')->label('Usuarios')->icon('heroicon-o-user')->color('gray')->url(UserResource::getUrl('index')),
            Action::make('manageStoreUsers')->label('Asignaciones de tienda')->icon('heroicon-o-building-storefront')->color('gray')->url(StoreUserResource::getUrl('index')),
        ];
    }

    /**
     * Todas las personas (choferes + ayudantes + usuarios), con su tipo, contacto, estado y enlace a su
     * ficha. Ordenadas por nombre. Filtradas por la búsqueda.
     *
     * @return list<array{name: string, type: string, tone: string, document: ?string, contact: ?string, active: bool, url: ?string}>
     */
    #[Computed]
    public function people(): array
    {
        $term = trim($this->search);
        $out = [];

        foreach ($this->drivers($term) as $r) {
            $out[] = $r;
        }
        foreach ($this->helpers($term) as $r) {
            $out[] = $r;
        }
        foreach ($this->users($term) as $r) {
            $out[] = $r;
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Conteos por tipo para la tira de KPIs.
     *
     * @return array{drivers: int, helpers: int, users: int}
     */
    #[Computed]
    public function counts(): array
    {
        try {
            return [
                'drivers' => DB::table('fleet_drivers')->count(),
                'helpers' => DB::table('fleet_helpers')->count(),
                'users' => DB::table('users')->count(),
            ];
        } catch (Throwable) {
            return ['drivers' => 0, 'helpers' => 0, 'users' => 0];
        }
    }

    /**
     * @return list<array{name: string, type: string, tone: string, document: ?string, contact: ?string, active: bool, url: ?string}>
     */
    private function drivers(string $term): array
    {
        try {
            $q = DB::table('fleet_drivers');
            if ($term !== '') {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('national_id', 'ilike', $like)->orWhere('phone_primary', 'ilike', $like));
            }

            $out = [];
            foreach ($q->orderBy('name')->limit(200)->get(['id', 'name', 'national_id', 'phone_primary', 'active']) as $r) {
                $out[] = [
                    'name' => (string) $r->name,
                    'type' => 'Chofer',
                    'tone' => 'agent',
                    'document' => $this->str($r->national_id),
                    'contact' => $this->str($r->phone_primary),
                    'active' => (bool) $r->active,
                    'url' => $this->url(DriverResource::class, (string) $r->id),
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
    private function helpers(string $term): array
    {
        try {
            $q = DB::table('fleet_helpers');
            if ($term !== '') {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('national_id', 'ilike', $like)->orWhere('phone', 'ilike', $like));
            }

            $out = [];
            foreach ($q->orderBy('name')->limit(200)->get(['id', 'name', 'national_id', 'phone', 'active']) as $r) {
                $out[] = [
                    'name' => (string) $r->name,
                    'type' => 'Ayudante',
                    'tone' => 'warning',
                    'document' => $this->str($r->national_id),
                    'contact' => $this->str($r->phone),
                    'active' => (bool) $r->active,
                    'url' => $this->url(HelperResource::class, (string) $r->id),
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
    private function users(string $term): array
    {
        try {
            $q = DB::table('users');
            if ($term !== '') {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            }

            // Primero la página de usuarios; luego sus roles acotados por id (no toda la tabla user_roles).
            $rows = $q->orderBy('name')->limit(200)->get(['id', 'name', 'email']);
            $roles = $this->userRoles(array_values($rows->pluck('id')->map(static fn ($id): int => (int) $id)->all()));

            $out = [];
            foreach ($rows as $r) {
                $role = $roles[(int) $r->id] ?? null;
                $out[] = [
                    'name' => (string) $r->name,
                    'type' => $role !== null ? 'Usuario · '.$this->roleLabel($role) : 'Usuario',
                    'tone' => 'positive',
                    'document' => null,
                    'contact' => $this->str($r->email),
                    'active' => true,
                    'url' => $this->url(UserResource::class, (string) $r->id),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Rol (string) por id de usuario, acotado a los ids de la página (no toda la tabla user_roles).
     *
     * @param  list<int>          $userIds
     * @return array<int, string>
     */
    private function userRoles(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            $out = [];
            foreach (DB::table('user_roles')->whereIn('user_id', $userIds)->get(['user_id', 'role']) as $r) {
                $out[(int) $r->user_id] ??= (string) $r->role;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Etiqueta legible del rol. */
    private function roleLabel(string $role): string
    {
        return match (strtolower($role)) {
            'admin', 'administrator' => 'Administrador',
            'supervisor' => 'Supervisor',
            'chofer', 'driver' => 'Chofer',
            'preparador', 'picker' => 'Preparador',
            'tienda', 'store' => 'Tienda',
            'transportista', 'carrier' => 'Transportista',
            default => ucfirst($role),
        };
    }

    /** Enlace tolerante a la ficha de un recurso (view → edit → index). @param class-string<\Filament\Resources\Resource> $resource */
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
