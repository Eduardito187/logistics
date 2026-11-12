<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Logistics\Carriers\Application\Command\ScheduleCarrierTrip\ScheduleCarrierTrip;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Alta de un viaje desde el admin.
 *
 * No persiste con Eloquent: despacha ScheduleCarrierTrip al bus CQRS con un id UUID v7 fijado acá, así
 * se recupera el registro exacto para la redirección sin adivinar. Una regla de negocio violada (envío ya
 * en otro viaje, transportista suspendido) se avisa en inglés y se detiene el alta sin 500.
 */
final class CreateCarrierTrip extends CreateRecord
{
    protected static string $resource = CarrierTripResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tripId = Str::uuid7()->toString();

        $shipmentIds = \is_array($data['shipment_ids'] ?? null)
            ? array_values(array_filter($data['shipment_ids'], static fn (mixed $v): bool => \is_string($v)))
            : [];
        $reference = $data['reference'] ?? null;

        try {
            app(CommandBus::class)->dispatch(new ScheduleCarrierTrip(
                carrierId: (string) $data['carrier_id'],
                corridorFrom: (string) $data['corridor_from'],
                corridorTo: (string) $data['corridor_to'],
                scheduledAt: (string) $data['scheduled_at'],
                reference: \is_string($reference) ? $reference : null,
                shipmentIds: $shipmentIds,
                tripId: $tripId,
            ));
        } catch (DomainException) {
            Notification::make()
                ->title(__('Trip not scheduled'))
                ->body(__('Check that the carrier is active, origin and destination differ, and no shipment is already on another active trip.'))
                ->danger()
                ->send();

            throw new Halt;
        }

        return CarrierTripRecord::query()->findOrFail($tripId);
    }

    protected function getRedirectUrl(): string
    {
        return CarrierTripResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
