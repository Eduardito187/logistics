<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\LegResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Infrastructure\Persistence\Eloquent\LegRecord;
use Logistics\Network\Presentation\Filament\Resources\LegResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un tramo/corredor desde el admin.
 *
 * No persiste con Eloquent: despacha DefineLegCommand al bus CQRS (valida que ambos nodos existan,
 * impide tramos duplicados, genera el id y publica el evento al outbox en la misma transacción). Como
 * el comando no devuelve el id, se recupera el tramo recién creado por su combinación única de
 * origen, destino y corredor para que Filament pueda redirigir a la vista del recurso.
 */
final class CreateLeg extends CreateRecord
{
    protected static string $resource = LegResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $fromNodeId = (string) $data['from_node_id'];
        $toNodeId = (string) $data['to_node_id'];
        $corridorType = (string) $data['corridor_type'];

        app(CommandBus::class)->dispatch(new DefineLegCommand(
            fromNodeId: $fromNodeId,
            toNodeId: $toNodeId,
            corridorType: $corridorType,
        ));

        // Origen + destino + corredor son únicos: identifican el tramo recién definido para redirigir.
        return LegRecord::query()
            ->where('from_node_id', $fromNodeId)
            ->where('to_node_id', $toNodeId)
            ->where('corridor_type', $corridorType)
            ->firstOrFail();
    }
}
