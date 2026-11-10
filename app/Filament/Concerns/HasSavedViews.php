<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\CockpitSavedView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

/**
 * Vistas guardadas por usuario para una página de listado (capa CRM). Da al usuario de oficina acciones
 * de cabecera para GUARDAR el estado actual del listado (pestaña + filtros) con un nombre y VOLVER a él
 * de un clic, además de BORRAR las que ya no usa. Reutilizable en cualquier `ListRecords` con pestañas.
 *
 * Es preferencia personal, privada por usuario y por recurso: se guarda en `cockpit_saved_views`
 * ({@see CockpitSavedView}) y nunca muta datos de negocio. Cada página la monta vía
 * `getSavedViewActions()` dentro de su `getHeaderActions()`.
 *
 * @mixin ListRecords
 */
trait HasSavedViews
{
    /**
     * Acciones de cabecera de vistas guardadas: "Save view" + el menú "My views" (aplicar / borrar).
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function getSavedViewActions(): array
    {
        return [
            $this->saveViewAction(),
            $this->myViewsActionGroup(),
        ];
    }

    /**
     * Guarda el estado actual del listado (pestaña activa + filtros de tabla) como una vista con nombre
     * del usuario actual, para ESTE recurso.
     */
    private function saveViewAction(): Action
    {
        return Action::make('saveView')
            ->label(__('Save view'))
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->modalHeading(__('Save current view'))
            ->modalDescription(__('Store the active segment and filters under a name to reuse them with one click.'))
            ->modalSubmitActionLabel(__('Save'))
            ->schema([
                TextInput::make('name')
                    ->label(__('View name'))
                    ->required()
                    ->maxLength(120),
            ])
            ->action(function (array $data): void {
                $userId = Filament::auth()->id();
                if ($userId === null) {
                    return;
                }

                CockpitSavedView::query()->create([
                    'user_id' => $userId,
                    'resource' => static::getResource(),
                    'name' => (string) $data['name'],
                    'payload' => [
                        'tab' => $this->activeTab,
                        'filters' => $this->tableFilters ?? [],
                    ],
                ]);

                Notification::make()
                    ->title(__('View saved'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Menú con las vistas guardadas del usuario para este recurso: cada una se aplica al elegirla, más
     * una acción para borrar una vista. Se oculta si el usuario no tiene ninguna guardada.
     */
    private function myViewsActionGroup(): ActionGroup
    {
        $views = $this->savedViews();

        $actions = $views
            ->map(fn (CockpitSavedView $view): Action => $this->applyViewAction($view))
            ->values()
            ->all();

        if ($views->isNotEmpty()) {
            $actions[] = $this->deleteViewAction($views);
        }

        return ActionGroup::make($actions)
            ->label(__('My views'))
            ->icon('heroicon-o-bookmark-square')
            ->button()
            ->color('gray')
            ->visible($views->isNotEmpty());
    }

    /**
     * Acción que aplica una vista guardada: restaura su pestaña y sus filtros en el listado.
     */
    private function applyViewAction(CockpitSavedView $view): Action
    {
        return Action::make('applySavedView'.$view->getKey())
            ->label($view->name)
            ->icon('heroicon-o-eye')
            ->action(function () use ($view): void {
                /** @var array{tab?: string|null, filters?: array<string, mixed>} $payload */
                $payload = $view->payload;

                $this->activeTab = is_string($payload['tab'] ?? null) ? $payload['tab'] : null;
                $this->tableFilters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
                $this->resetPage();

                Notification::make()
                    ->title(__('View applied'))
                    ->body($view->name)
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción para borrar una de las vistas guardadas del usuario (elegida de un select).
     *
     * @param Collection<int, CockpitSavedView> $views
     */
    private function deleteViewAction(Collection $views): Action
    {
        return Action::make('deleteSavedView')
            ->label(__('Delete a view'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete a saved view'))
            ->schema([
                Select::make('view_id')
                    ->label(__('View'))
                    ->required()
                    ->native(false)
                    ->options($views->pluck('name', 'id')->all()),
            ])
            ->action(function (array $data): void {
                $userId = Filament::auth()->id();
                if ($userId === null) {
                    return;
                }

                CockpitSavedView::query()
                    ->where('user_id', $userId)
                    ->where('resource', static::getResource())
                    ->whereKey($data['view_id'])
                    ->delete();

                Notification::make()
                    ->title(__('View deleted'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Vistas guardadas del usuario actual para este recurso.
     *
     * @return Collection<int, CockpitSavedView>
     */
    private function savedViews(): Collection
    {
        $userId = Filament::auth()->id();
        if ($userId === null) {
            return new Collection;
        }

        return CockpitSavedView::query()
            ->where('user_id', $userId)
            ->where('resource', static::getResource())
            ->orderBy('name')
            ->get();
    }
}
