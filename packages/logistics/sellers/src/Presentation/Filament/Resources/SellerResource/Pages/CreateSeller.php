<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Sellers\Application\Command\RegisterSeller\RegisterSeller;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un seller desde el admin.
 *
 * No persiste con Eloquent: despacha el comando RegisterSeller al bus CQRS (el dominio genera el id).
 * Como el comando no devuelve el id, se recupera el record recién creado por su NIT (único) para que
 * Filament pueda redirigir a la vista del recurso.
 */
final class CreateSeller extends CreateRecord
{
    protected static string $resource = SellerResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $taxId = (string) $data['tax_id'];
        $phone = isset($data['contact_phone']) && $data['contact_phone'] !== ''
            ? (string) $data['contact_phone']
            : null;

        app(CommandBus::class)->dispatch(new RegisterSeller(
            legalName: (string) $data['legal_name'],
            taxId: $taxId,
            contactName: (string) $data['contact_name'],
            email: (string) $data['contact_email'],
            phone: $phone,
        ));

        // El NIT es único: identifica el agregado recién registrado para la redirección del admin.
        return SellerRecord::query()->where('tax_id', $taxId)->firstOrFail();
    }
}
