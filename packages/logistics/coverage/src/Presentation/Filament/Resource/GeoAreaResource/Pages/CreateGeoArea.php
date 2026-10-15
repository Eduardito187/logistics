<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource;

/**
 * Alta de un área geográfica.
 *
 * La clave primaria es un UUID v7 (convención de la plataforma); el registro Eloquent no la
 * autogenera, así que se asigna acá antes de insertar.
 */
final class CreateGeoArea extends CreateRecord
{
    protected static string $resource = GeoAreaResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = Str::uuid7()->toString();

        return $data;
    }
}
