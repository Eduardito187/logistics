{{-- Cockpit Inbound board: ASNs del día por estado con acciones inline que despachan Commands por el
     CommandBus. Operaciones-primero (mismo patrón que LastMileBoard). Texto visible en inglés (regla 5). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($segments as $segment)
            <x-filament::section>
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        {{ $segment['label'] }}
                        <x-filament::badge :color="$segment['color']">{{ $segment['count'] }}</x-filament::badge>
                    </span>
                </x-slot>

                @if (count($segment['rows']) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing here right now.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4">{{ __('Seller') }}</th>
                                    <th class="py-2 pr-4">{{ __('Warehouse') }}</th>
                                    <th class="py-2 pr-4">{{ __('Appointment') }}</th>
                                    <th class="py-2 pr-4">{{ __('Lines') }}</th>
                                    <th class="py-2 pr-4">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($segment['rows'] as $row)
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4 font-medium">{{ $row['seller'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['warehouse'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['appointment'] ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $row['lines'] }}</td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2">
                                                @if ($segment['key'] === 'announced' || $segment['key'] === 'scheduled')
                                                    {{ ($this->scheduleAction)(['asn' => $row['id']]) }}
                                                @endif
                                                @if ($segment['key'] === 'scheduled')
                                                    {{ ($this->arrivalAction)(['asn' => $row['id']]) }}
                                                @endif
                                                @if ($segment['key'] === 'received')
                                                    {{ ($this->labelAction)(['asn' => $row['id']]) }}
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        <p class="text-xs text-gray-400">{{ __('Updated at') }} {{ $generatedAt }}</p>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
