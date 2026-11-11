<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Pages;

use Filament\Pages\Page;
use Logistics\Labels\Presentation\Filament\Configuration\LabelFormatSection;
use Logistics\Settings\Presentation\Filament\Pages\Configuration;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Ruta antigua del formato de etiqueta: la edición vive ahora en la pantalla «Configuración»
 * (pestaña Labels › Label format, {@see LabelFormatSection}). Se conserva solo para que los enlaces y
 * marcadores viejos sigan funcionando: redirige y no aparece en el menú.
 */
final class LabelFormatSettings extends Page
{
    use OfficeOnly;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.labels.label-format-settings';

    public function mount(): void
    {
        $this->redirect(Configuration::sectionUrl(LabelFormatSection::slug()));
    }
}
