<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StoreUserResource\Pages\ListStoreUsers;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin Filament para asignar el nodo de tienda (rol tienda) o el transportista (rol transportista) a una
 * cuenta de usuario de la app.
 *
 * `App\Models\User` (login de la app, tabla `users`) no tiene un Resource propio; esta pantalla cubre
 * la regla de negocio "un usuario de tienda pertenece a UN nodo de tipo store". No es un CRUD de
 * usuarios: solo lista las cuentas y permite fijar/limpiar su `node_id` contra los nodos store reales.
 *
 * El nodo se referencia por su UUID (sin acople al dominio de Network: se lee `network_nodes` por
 * consulta directa). Todo texto visible va en inglés (regla 5 del proyecto).
 *
 * Igual con el transportista: `users.carrier_id` es la identidad SERVER-SIDE del rol transportista (los
 * endpoints de liquidaciones acotan por ella; la app la lee de /auth/me). Se elige por nombre contra la
 * tabla `carriers` (consulta directa, sin acoplar al dominio de Carriers); nunca se muestra el UUID.
 *
 * @extends resource<User>
 */
final class StoreUserResource extends Resource
{
    // Asignaciones de tienda: alcance de oficina (admin/supervisor), mismo criterio que el panel.
    use OfficeOnly;

    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 14;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    // Gestión de cuentas de usuario de tienda (usuario↔nodo-tienda): es gestión de PERSONAS/cuentas, no de
    // la red. Va con Usuarios en el grupo «Personal».
    // Fuera del menú: la asignación de tienda es un dato de una persona (usuario de tienda); se gestiona
    // desde el DIRECTORIO de personas, no como ítem CRUD suelto en el menú.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Personnel');
    }

    public static function getNavigationLabel(): string
    {
        return __('Store assignments');
    }

    public static function getModelLabel(): string
    {
        return __('Store user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Store users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Nodo de tienda: select buscable con nodos reales (type='store'), nunca el UUID a pelo.
            // Se lee `network_nodes` por consulta directa para no acoplar este panel al dominio de Network.
            Select::make('node_id')
                ->label(__('Store node'))
                ->native(false)
                ->searchable()
                ->placeholder(__('Unassigned'))
                ->helperText(__('Store node this account belongs to. Leave empty to unassign.'))
                ->getSearchResultsUsing(fn (string $search): array => DB::table('network_nodes')
                    ->where('type', 'store')
                    ->where(function ($query) use ($search): void {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%");
                    })
                    ->orderBy('code')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(static fn ($node): array => [$node->id => "{$node->code} — {$node->name}"])
                    ->all())
                ->getOptionLabelUsing(function ($value): ?string {
                    $node = DB::table('network_nodes')->where('id', $value)->first();

                    return $node === null ? null : "{$node->code} — {$node->name}";
                }),
            // Transportista de la cuenta (rol transportista): select buscable por nombre o NIT contra los
            // transportistas reales; nunca el UUID a pelo.
            Select::make('carrier_id')
                ->label(__('Carrier'))
                ->native(false)
                ->searchable()
                ->placeholder(__('Unassigned'))
                ->helperText(__('Carrier this account belongs to (carrier role only). Leave empty to unassign.'))
                ->getSearchResultsUsing(fn (string $search): array => DB::table('carriers')
                    ->where(function ($query) use ($search): void {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('tax_id', 'ilike', "%{$search}%");
                    })
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(static fn ($carrier): array => [$carrier->id => "{$carrier->name} ({$carrier->tax_id})"])
                    ->all())
                ->getOptionLabelUsing(function ($value): ?string {
                    $carrier = DB::table('carriers')->where('id', $value)->first();

                    return $carrier === null ? null : "{$carrier->name} ({$carrier->tax_id})";
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable(),
                // Nodo de tienda asignado, resuelto a "CODE — Name" leyendo network_nodes por consulta directa.
                TextColumn::make('node')
                    ->label(__('Store node'))
                    ->placeholder('—')
                    ->getStateUsing(function (User $record): ?string {
                        if ($record->node_id === null) {
                            return null;
                        }
                        $node = DB::table('network_nodes')->where('id', $record->node_id)->first();

                        return $node === null ? null : "{$node->code} — {$node->name}";
                    }),
                // Transportista asignado, resuelto a su nombre leyendo carriers por consulta directa.
                TextColumn::make('carrier')
                    ->label(__('Carrier'))
                    ->placeholder('—')
                    ->getStateUsing(function (User $record): ?string {
                        if ($record->carrier_id === null) {
                            return null;
                        }
                        $name = DB::table('carriers')->where('id', $record->carrier_id)->value('name');

                        return is_string($name) ? $name : null;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Edit assignment'))
                    ->icon('heroicon-o-building-storefront')
                    ->modalHeading(__('Edit assignment')),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStoreUsers::route('/'),
        ];
    }
}
