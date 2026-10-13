<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un ayudante desde el admin.
 *
 * Despacha RegisterHelper por el CommandBus (el UUID lo genera el dominio) y relee el ayudante recién
 * creado (el más reciente con ese nombre) para que Filament muestre el resultado.
 */
final class CreateHelper extends CreateRecord
{
    protected static string $resource = HelperResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<array{type: string, expires_on: string}> $documents */
        $documents = array_values($data['documents'] ?? []);

        app(CommandBus::class)->dispatch(new RegisterHelper(
            name: (string) $data['name'],
            phone: isset($data['phone']) && $data['phone'] !== '' ? (string) $data['phone'] : null,
            documents: $documents,
            active: (bool) ($data['active'] ?? true),
        ));

        return HelperModel::query()
            ->where('name', $data['name'])
            ->latest()
            ->firstOrFail();
    }
}
