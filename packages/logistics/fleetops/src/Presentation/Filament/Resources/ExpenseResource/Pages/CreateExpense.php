<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\RecordExpense\RecordExpense;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\ExpenseRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un gasto de flota desde el admin.
 *
 * No persiste con Eloquent: despacha RecordExpense al bus CQRS (el dominio genera el id). El importe se
 * captura en bolivianos y se convierte a centavos aquí. Como el comando no devuelve el id, se recupera el
 * record recién creado del vehículo (UUID v7 ordenable: el id más alto es el nuevo) para la redirección.
 */
final class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];
        $amountCents = (int) round(((float) $data['amount_bs']) * 100);

        app(CommandBus::class)->dispatch(new RecordExpense(
            vehicleId: $vehicleId,
            category: (string) $data['category'],
            amountCents: $amountCents,
            currency: (string) $data['currency'],
            incurredAt: Carbon::parse((string) $data['incurred_at'])->toIso8601String(),
            description: isset($data['description']) && is_string($data['description']) && $data['description'] !== '' ? $data['description'] : null,
            note: isset($data['note']) && is_string($data['note']) && $data['note'] !== '' ? $data['note'] : null,
        ));

        return ExpenseRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
