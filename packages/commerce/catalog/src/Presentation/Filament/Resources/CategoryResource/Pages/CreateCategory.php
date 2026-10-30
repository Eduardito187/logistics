<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;

use Commerce\Catalog\Application\Command\CreateCategory\CreateCategory as CreateCategoryCommand;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una categoría desde el admin.
 *
 * No persiste con Eloquent: despacha el comando CreateCategory al bus CQRS (el dominio genera el id y
 * normaliza el slug). Como el comando no devuelve el id, se recupera el record recién creado por su slug
 * normalizado (natural key único) para la redirección del admin.
 */
final class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];

        // Si no se escribió slug, se deriva del nombre (el dominio lo normaliza igual).
        $slugSource = trim((string) ($data['slug'] ?? '')) !== ''
            ? (string) $data['slug']
            : $name;

        $slug = $this->normalizeSlug($slugSource);

        $parentId = isset($data['parent_id']) && (string) $data['parent_id'] !== ''
            ? (string) $data['parent_id']
            : null;

        app(CommandBus::class)->dispatch(new CreateCategoryCommand(
            name: $name,
            slug: $slug,
            parentId: $parentId,
        ));

        // El slug normalizado es único: identifica la categoría recién creada para la redirección.
        return CategoryRecord::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * Normaliza el slug igual que el value object Slug del dominio (minúsculas, guiones, sin bordes).
     * Se hace en presentación solo para poder recuperar el record por su slug tras el alta; el dominio
     * vuelve a normalizar de forma idempotente.
     */
    private function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim($normalized, '-');
    }
}
