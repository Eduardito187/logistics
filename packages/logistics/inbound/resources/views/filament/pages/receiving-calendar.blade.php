{{-- Calendario de andén de recepción: vista de los 7 días de la semana con las citas agendadas por
     franja. Espejo del DispatchCalendar, del lado de entrada. Texto visible en inglés (regla 5). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex items-center gap-2">
                <x-filament::button color="gray" wire:click="shiftWeek(-1)" icon="ds-chevron-left">
                    {{ __('Previous week') }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="goToThisWeek">
                    {{ __('This week') }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="shiftWeek(1)" icon="ds-chevron-right" icon-position="after">
                    {{ __('Next week') }}
                </x-filament::button>
                <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ $weekStart }} — {{ $weekEnd }}
                </span>
            </div>
        </x-filament::section>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-7">
            @foreach ($week as $day)
                <div @class([
                    'rounded-lg border p-3',
                    'border-primary-400 dark:border-primary-500' => $day['is_today'],
                    'border-gray-200 dark:border-gray-700' => ! $day['is_today'],
                ])>
                    <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                        {{ $day['weekday_label'] }}
                        <span class="block text-xs font-normal text-gray-400">{{ $day['date'] }}</span>
                    </div>

                    @if (count($day['slots']) === 0)
                        <p class="text-xs text-gray-400">{{ __('No appointments') }}</p>
                    @else
                        <ul class="space-y-1">
                            @foreach ($day['slots'] as $slot)
                                <li class="flex items-center justify-between text-xs">
                                    <span class="text-gray-600 dark:text-gray-300">{{ $slot['time_slot'] }}</span>
                                    <x-filament::badge color="info">{{ $slot['appointments'] }}</x-filament::badge>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-400">
                            {{ __('Appointments') }}: {{ $day['appointments'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
