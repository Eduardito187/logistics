<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource;

/**
 * Listado de reglas de cascada personalizadas por tipo de mensaje.
 *
 * Expone además el historial versionado de la clave `notifications.cascade_rules`: la acción "Version
 * history" abre un modal de solo lectura con cada versión publicada (quién, cuándo y cuántos tipos
 * personalizaba), leída del contexto Configuración a través del puerto CascadeRuleSettings.
 */
final class ListMessageTypeRules extends ListRecords
{
    protected static string $resource = MessageTypeRuleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label(__('Version history'))
                ->icon('ds-clock')
                ->color('gray')
                ->modalHeading(__('Cascade rules version history'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (): View => view('notifications::filament.cascade-history', [
                    'versions' => app(CascadeRuleSettings::class)->history(),
                ])),
            CreateAction::make(),
        ];
    }
}
