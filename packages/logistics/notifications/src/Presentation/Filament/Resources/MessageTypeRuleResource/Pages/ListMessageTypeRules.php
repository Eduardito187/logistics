<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource;

/**
 * Listado de reglas de cascada personalizadas por tipo de mensaje.
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
            CreateAction::make(),
        ];
    }
}
