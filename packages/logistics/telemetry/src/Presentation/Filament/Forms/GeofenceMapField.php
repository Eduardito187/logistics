<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Forms;

use Filament\Forms\Components\Field;

/**
 * Campo de formulario que permite DIBUJAR el polígono de una geocerca sobre un mapa Leaflet con
 * Leaflet-Geoman (sin API key). El estado del campo es la lista de vértices {lat,lng} que luego el
 * recurso envía al comando DefineGeofence/RedrawGeofence.
 *
 * Es solo presentación: no toca dominio ni persistencia. El blade entabla (entangle) el estado con la
 * capa Alpine/Livewire para leer y escribir el polígono dibujado.
 */
final class GeofenceMapField extends Field
{
    protected string $view = 'logistics-telemetry::filament.forms.geofence-map';

    protected function setUp(): void
    {
        parent::setUp();

        // Polígono vacío por defecto: el usuario lo dibuja en el mapa.
        $this->default([]);
    }
}
