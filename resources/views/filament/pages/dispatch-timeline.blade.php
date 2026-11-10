{{--
    Línea de tiempo (Gantt) del despacho del día (DisTrack OS, Fase 3 — workflows visibles). Un carril por
    conductor/vehículo con las paradas de su ruta ubicadas sobre un eje horario común (por su ETA). Datos
    reales y tolerantes vía App\Support\Cockpit\DispatchTimeline ($this->board). Capa `.distrack-cockpit`.
    Sin UUIDs: carril = placa + conductor; parada = referencia/cliente.
--}}
<x-filament-panels::page>
    @php
        $board = $this->board;
        $lanes = $board['lanes'] ?? [];
        $window = $board['window'] ?? ['start' => '', 'end' => '', 'ticks' => []];
        $totalStops = 0;
        foreach ($lanes as $lane) { $totalStops += (int) ($lane['stop_count'] ?? 0); }
    @endphp

    <div class="distrack-cockpit" wire:poll.60s>
        <div class="ck-hub__head" style="margin-bottom:1.1rem">
            <div style="min-width:0">
                <div class="ck-hub__title">{{ __('Dispatch timeline') }}</div>
                <div class="ck-hub__sub">
                    {{ __('The day of dispatch, lane by driver — stops placed on a common time axis by their ETA.') }}
                </div>
            </div>
            <div class="ck-hub__aside">
                <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ count($lanes) }} {{ __('lanes') }}</span>
                <span class="ck-pill">{{ number_format($totalStops) }} {{ __('stops') }}</span>
                <span class="ck-pill">{{ $board['date'] ?? '' }}</span>
            </div>
        </div>

        @if (count($lanes) === 0)
            <x-cockpit.card>
                <div style="padding:1.5rem;text-align:center;color:var(--ck-ink-3)">{{ __('No routes for this day yet.') }}</div>
            </x-cockpit.card>
        @else
            <div class="ck-gantt">
                {{-- Eje horario --}}
                <div class="ck-gantt__row ck-gantt__row--axis">
                    <div class="ck-gantt__label ck-gantt__label--axis">{{ $window['start'] }}–{{ $window['end'] }}</div>
                    <div class="ck-gantt__track ck-gantt__track--axis">
                        @foreach ($window['ticks'] as $tick)
                            <div class="ck-gantt__tick" style="left:{{ $tick['left'] }}%">
                                <span class="ck-gantt__tick-label">{{ $tick['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Un carril por conductor --}}
                @foreach ($lanes as $lane)
                    <div class="ck-gantt__row">
                        <div class="ck-gantt__label">
                            <span class="ck-gantt__plate">{{ $lane['vehicle'] }}</span>
                            <span class="ck-gantt__driver">{{ $lane['driver'] }}</span>
                            <span class="ck-gantt__count">{{ (int) $lane['stop_count'] }} {{ __('stops') }}</span>
                        </div>
                        <div class="ck-gantt__track">
                            @foreach ($window['ticks'] as $tick)
                                <div class="ck-gantt__grid" style="left:{{ $tick['left'] }}%"></div>
                            @endforeach
                            @foreach ($lane['stops'] as $stop)
                                <div class="ck-gantt__stop ck-gantt__stop--{{ $stop['tone'] }}"
                                     style="left:{{ $stop['left'] }}%"
                                     title="{{ $stop['eta'] }} · {{ $stop['ref'] }}">
                                    <span class="ck-gantt__dot"></span>
                                    <span class="ck-gantt__stop-label">{{ $stop['eta'] }} {{ $stop['ref'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
