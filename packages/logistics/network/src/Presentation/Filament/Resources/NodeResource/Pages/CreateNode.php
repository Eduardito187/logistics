<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un nodo desde el admin.
 *
 * No persiste con Eloquent: despacha RegisterNodeCommand al bus CQRS (el dominio genera el id, valida
 * el código único y publica el evento al outbox en la misma transacción). Como el comando no devuelve
 * el id, se recupera el record recién creado por su código (único) para que Filament pueda redirigir
 * a la vista del recurso.
 */
final class CreateNode extends CreateRecord
{
    protected static string $resource = NodeResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $code = (string) $data['code'];

        app(CommandBus::class)->dispatch(new RegisterNodeCommand(
            code: $code,
            name: (string) $data['name'],
            type: (string) $data['type'],
            latitude: (float) $data['lat'],
            longitude: (float) $data['lng'],
        ));

        // El código es único: identifica el agregado recién registrado para la redirección del admin.
        return NodeRecord::query()->where('code', $code)->firstOrFail();
    }
}
