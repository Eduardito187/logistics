{{--
    Ficha del cliente: datos básicos de contacto, listado de pedidos (cada uno enlazado a su detalle
    en una pestaña nueva), direcciones de entrega y mapa Leaflet + OpenStreetMap con todas sus
    ubicaciones. Layout responsivo con grillas que se adaptan al ancho (sin grandes espacios en blanco).
    Replica el patrón de carga de Leaflet del mapa de coberturas (@assets / @script).
--}}
@php
    use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

    /** @var bool $hasCustomer */
    /** @var string|null $customerName */
    /** @var string|null $documentId */
    /** @var string|null $phone */
    /** @var string|null $email */
    /** @var int $ordersCount */
    /** @var \Illuminate\Support\Collection $orders */
    /** @var array $addresses */
    /** @var array $mapPoints */
@endphp

<x-filament-panels::page>
    @unless ($hasCustomer)
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
            <x-filament::icon icon="heroicon-o-user" class="mx-auto mb-3 h-10 w-10 text-gray-400" />
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('No customer selected, or no orders found for this customer.') }}
            </p>
        </div>
    @else
        <div class="space-y-6">
            {{-- Datos básicos del cliente --}}
            <x-filament::section icon="heroicon-o-identification">
                <x-slot name="heading">{{ __('Customer details') }}</x-slot>
                <x-slot name="description">{{ __('Basic identity and contact information.') }}</x-slot>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Customer name') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $customerName }}</dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Identity document') }}</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $documentId ?? '—' }}</dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Phone') }}</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                            @if ($phone)
                                <a href="tel:{{ $phone }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $phone }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Email') }}</dt>
                        <dd class="mt-1 truncate text-sm text-gray-950 dark:text-white">
                            @if ($email)
                                <a href="mailto:{{ $email }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $email }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total orders') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $ordersCount }}</dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Delivery addresses') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ count($addresses) }}</dd>
                    </div>
                </dl>
            </x-filament::section>

            {{-- Mapa de todas las direcciones de entrega del cliente --}}
            <x-filament::section icon="heroicon-o-map">
                <x-slot name="heading">{{ __('Delivery locations') }}</x-slot>
                <x-slot name="description">{{ __('All delivery addresses of this customer on the map.') }}</x-slot>

                @if (count($mapPoints) > 0)
                    <div id="customer-profile-map" style="height: 420px; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
                @else
                    <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                        {{ __('None of this customer\'s addresses have coordinates yet.') }}
                    </div>
                @endif
            </x-filament::section>

            {{-- Pedidos y direcciones, lado a lado en pantallas anchas --}}
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <x-filament::section icon="heroicon-o-inbox-arrow-down">
                    <x-slot name="heading">{{ __('Orders') }}</x-slot>

                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($orders as $order)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $order->external_id }}
                                        <span class="text-gray-400">·</span>
                                        <span class="text-gray-500 dark:text-gray-400">{{ $order->source }}</span>
                                        <span class="text-gray-400">·</span>
                                        <span class="text-gray-500 dark:text-gray-400">{{ $order->status }}</span>
                                    </p>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $order->address_text }}</p>
                                </div>
                                <a
                                    href="{{ ShipmentResource::getUrl('view', ['record' => $order->getKey()]) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    {{ __('View order') }} &rarr;
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>

                <x-filament::section icon="heroicon-o-map-pin">
                    <x-slot name="heading">{{ __('Delivery addresses') }}</x-slot>

                    <ul class="space-y-3">
                        @foreach ($addresses as $address)
                            <li class="rounded-lg border border-gray-100 p-3 dark:border-white/10">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $address['text'] }}</p>
                                @if ($address['reference'])
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $address['reference'] }}</p>
                                @endif
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                                        {{ $address['count'] }} {{ $address['count'] === 1 ? __('order') : __('orders') }}
                                    </span>
                                    @if ($address['lat'] !== null && $address['lng'] !== null)
                                        <span>{{ $address['lat'] }}, {{ $address['lng'] }}</span>
                                    @else
                                        <span>{{ __('No coordinates') }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            </div>
        </div>
    @endunless

    @assets
        {{-- Leaflet vendorizado localmente: cero dependencias externas en runtime (servido desde self). --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
    @endassets

    @script
    <script>
        const points = @js($mapPoints);
        const el = document.getElementById('customer-profile-map');

        if (el && points.length > 0) {
            const map = L.map(el);
            // Teselas self-host con fallback automático al OSM público (helper compartido): si el
            // tileserver del proyecto no responde, la capa baja sola al tile público y el mapa no queda gris.
            window.DismacMaps.tileLayer(L, {
                url: @js(config('maps.tile_url')),
                fallbackUrl: @js(config('maps.tile_fallback_url')),
                attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
            }).addTo(map);

            const markers = [];
            points.forEach((p) => {
                const marker = L.marker([p.lat, p.lng]).addTo(map);
                if (p.text) {
                    marker.bindPopup(p.text);
                }
                markers.push(marker);
            });

            if (markers.length === 1) {
                map.setView([points[0].lat, points[0].lng], 15);
            } else {
                const group = L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.2));
            }

            // El contenedor recién es visible tras el render de Livewire: recalcular tamaño.
            setTimeout(() => map.invalidateSize(), 250);
        }
    </script>
    @endscript
</x-filament-panels::page>
