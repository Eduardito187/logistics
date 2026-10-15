{{-- Cockpit de última milla: entregas del día segmentadas por estado operativo, con acciones de
     dominio inline por fila. Texto visible en inglés (regla 5). Sin UUIDs: todo legible, huecos a '—'. --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Resumen rápido: cuántas entregas hay en cada segmento operativo. --}}
        <x-filament::section>
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-4">
                    @foreach ($segments as $segment)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $segment['label'] }}
                            </dt>
                            <dd class="text-2xl font-semibold tabular-nums text-{{ $segment['color'] }}-600 dark:text-{{ $segment['color'] }}-400">
                                {{ $segment['count'] }}
                            </dd>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('As of :time', ['time' => $generatedAt->format('Y-m-d H:i')]) }}
                </p>
            </div>
        </x-filament::section>

        {{-- Un bloque por segmento; cada entrega es una fila con sus acciones inline cuando aplica. --}}
        @foreach ($segments as $segment)
            <x-filament::section>
                <x-slot name="heading">
                    <span class="inline-flex items-center gap-2">
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold',
                            "bg-{$segment['color']}-100 text-{$segment['color']}-700 dark:bg-{$segment['color']}-950 dark:text-{$segment['color']}-300",
                        ])>
                            {{ $segment['label'] }}
                        </span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $segment['count'] }}</span>
                    </span>
                </x-slot>

                @forelse ($segment['rows'] as $row)
                    <div class="flex flex-col gap-3 border-b border-gray-100 py-3 last:border-0 md:flex-row md:items-center md:justify-between dark:border-gray-800">
                        <div class="flex items-start gap-3">
                            {{-- Foto del POD (si existe): visible como imagen, nunca un hueco roto. URL externa
                                 (CDN): el onerror cambia a un placeholder embebido tras el primer fallo. --}}
                            @if (! empty($row['pod_photo_url']))
                                <img
                                    src="{{ $row['pod_photo_url'] }}"
                                    alt="{{ __('Proof of delivery photo') }}"
                                    loading="lazy"
                                    onerror="this.onerror=null;this.src='{{ $podPlaceholder }}';"
                                    class="h-12 w-12 rounded-md bg-gray-50 object-cover"
                                />
                            @endif

                            <div class="space-y-0.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-semibold">{{ $row['order'] ?? '—' }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-medium',
                                        "bg-{$row['status_color']}-100 text-{$row['status_color']}-700 dark:bg-{$row['status_color']}-950 dark:text-{$row['status_color']}-300",
                                    ])>
                                        {{ $row['status_label'] }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-700 dark:text-gray-200">{{ $row['customer'] ?? '—' }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['address'] ?? '—' }}</div>
                                <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span>{{ __('Vehicle') }}: <span class="font-mono">{{ $row['vehicle'] ?? '—' }}</span></span>
                                    <span>{{ __('Promised') }}: {{ $row['promised'] ?? '—' }}</span>
                                    @if (! empty($row['delivered_at']))
                                        <span>{{ __('Delivered at') }}: {{ $row['delivered_at'] }}</span>
                                    @endif
                                    @if (! empty($row['failure_reason']))
                                        <span class="text-danger-600 dark:text-danger-400">{{ __('Failure reason') }}: {{ $row['failure_reason'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Acciones de dominio inline: solo para entregas aún abiertas. Despachan Commands
                             por el CommandBus (ver LastMileBoard). El pedido viaja como argumento. --}}
                        @if ($segment['actionable'])
                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                {{ ($this->confirmDeliveryAction)(['shipment' => $row['shipment_id']]) }}
                                {{ ($this->markFailedAction)(['shipment' => $row['shipment_id']]) }}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Nothing here right now.') }}
                    </div>
                @endforelse
            </x-filament::section>
        @endforeach
    </div>

    {{-- Modales de las acciones inline: una sola instancia por acción, reutilizada por fila. --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
