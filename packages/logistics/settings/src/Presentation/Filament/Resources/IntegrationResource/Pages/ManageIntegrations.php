<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources\IntegrationResource\Pages;

use Filament\Resources\Pages\Page;
use Logistics\Settings\Presentation\Filament\Configuration\Integrations\RoutingSection;
use Logistics\Settings\Presentation\Filament\Pages\Configuration;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource;

/**
 * Ruta antigua del panel de Integraciones: la edición vive ahora en la pantalla «Configuración»
 * (pestaña Integrations: Routing, SAP connector, Storage, Push notifications). Se conserva solo para que
 * los enlaces y marcadores viejos sigan funcionando: redirige a la primera sección de integraciones.
 */
final class ManageIntegrations extends Page
{
    protected static string $resource = IntegrationResource::class;

    protected string $view = 'logistics-settings::filament.pages.manage-integrations';

    public function mount(): void
    {
        $this->redirect(Configuration::sectionUrl(RoutingSection::slug()));
    }
}
