{{--
    Mapa de la ubicación de entrega del pedido (Leaflet + OpenStreetMap, sin API key).
    Replica el patrón de carga del mapa de coberturas: @assets para el CDN de Leaflet y
    @script para inicializar el mapa. Si el pedido no tiene coordenadas, muestra un aviso.
--}}
@php
    /** @var \Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord|null $record */
    $record = $getRecord();
    $lat = $record?->lat !== null ? (float) $record->lat : null;
    $lng = $record?->lng !== null ? (float) $record->lng : null;
    $hasCoords = $lat !== null && $lng !== null;
    $customerName = $record?->customer_name;
    $addressText = $record?->address_text;
@endphp

<div>
    @if ($hasCoords)
        <div
            id="shipment-delivery-map"
            style="height: 420px; width: 100%; border-radius: 0.5rem; z-index: 0;"
        ></div>
    @else
        <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
            {{ __('This order has no delivery coordinates yet. Re-geocode the address to place it on the map.') }}
        </div>
    @endif
</div>

@assets
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('js/dismac-maps.js') }}"></script>
@endassets

@script
<script>
    const lat = @js($lat);
    const lng = @js($lng);
    const customerName = @js($customerName);
    const addressText = @js($addressText);
    const el = document.getElementById('shipment-delivery-map');

    if (el && lat !== null && lng !== null) {
        const map = L.map(el).setView([lat, lng], 15);
        // Teselas self-host con fallback automático al OSM público (helper compartido): si el
        // tileserver del proyecto no responde, la capa baja sola al tile público y el mapa no queda gris.
        window.DismacMaps.tileLayer(L, {
            url: @js(config('maps.tile_url')),
            fallbackUrl: @js(config('maps.tile_fallback_url')),
            attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
            maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
        }).addTo(map);

        const parts = [];
        if (customerName) {
            parts.push('<strong>' + customerName + '</strong>');
        }
        if (addressText) {
            parts.push(addressText);
        }

        const marker = L.marker([lat, lng]).addTo(map);
        if (parts.length) {
            marker.bindPopup(parts.join('<br>')).openPopup();
        }

        // El contenedor recién es visible tras el render de Livewire: recalcular tamaño.
        setTimeout(() => map.invalidateSize(), 250);
    }
</script>
@endscript
