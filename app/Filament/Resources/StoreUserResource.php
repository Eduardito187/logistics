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
 * Admin Filament para asignar el nodo de tienda a una cuenta de usuario de la app.
 *
 * `App\Models\User` (login de la app, tabla `users`) no tiene un Resource propio; esta pantalla cubre
 * la regla de negocio "un usuario de tienda pertenece a UN nodo de tipo store". No es un CRUD de
 * usuarios: solo lista las cuentas y permite fijar/limpiar su `node_id` contra los nodos store reales.
 *
 * El nodo se referencia por su UUID (sin acople al dominio de Network: se lee `network_nodes` por
 * consulta directa). Todo texto visible va en inglés (regla 5 del proyecto).
 *
 * @extends resource<User>
 */
final class StoreUserResource extends Resource
{
    // Asignaciones de tienda: alcance de oficina (admin/supervisor), mismo criterio que el panel.
    use OfficeOnly;

    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 20;

    public static function getNavigationIcon(): string
    {
        return 'ds-building-storefront';
    }

    public static function getNavigationGroup(): string
    {
        return __('Network');
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
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Assign store'))
                    ->icon('ds-building-storefront')
                    ->modalHeading(__('Assign store node')),
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
