<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Carriers\Application\Command\RegisterCarrier\RegisterCarrier;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un transportista desde el admin.
 *
 * No persiste con Eloquent: despacha el comando RegisterCarrier al bus CQRS (el dominio genera el id).
 * Como el comando no devuelve el id, se recupera el record recién creado por su NIT (único) para que
 * Filament pueda redirigir a la vista del recurso.
 */
final class CreateCarrier extends CreateRecord
{
    protected static string $resource = CarrierResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<array{type: string, reference: string}> $documents */
        $documents = array_values(is_array($data['documents'] ?? null) ? $data['documents'] : []);

        $taxId = (string) $data['tax_id'];

        app(CommandBus::class)->dispatch(new RegisterCarrier(
            name: (string) $data['name'],
            taxId: $taxId,
            documents: $documents,
        ));

        // El NIT es único: identifica el agregado recién registrado para la redirección del admin.
        return CarrierRecord::query()->where('tax_id', $taxId)->firstOrFail();
    }
}
