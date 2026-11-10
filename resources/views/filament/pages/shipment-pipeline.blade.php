{{--
    Pipeline de última milla (DisTrack OS, Fase 3 — workflows visibles). Tablero por estado del ciclo de
    vida del envío: columnas (recibido → planificado → en ejecución → entregado / fallido) con tarjetas
    clickeables al hub del envío. Rojo = ventana comprometida vencida. Datos reales y tolerantes vía
    App\Support\Cockpit\ShipmentPipeline ($this->columns). Capa `.distrack-cockpit`. Refresco por wire:poll.
--}}
<x-filament-panels::page>
    @php
        $cols = $this->columns;
        $totalLate = array_sum(array_column($cols, 'late_count'));
        $totalActive = 0;
        foreach ($cols as $col) {
            if (in_array($col['status'], ['RECEIVED', 'PLANNED', 'IN_EXECUTION'], true)) {
                $totalActive += $col['count'];
            }
        }
    @endphp

    <div class="distrack-cockpit" wire:poll.60s>
        <div class="ck-hub__head" style="margin-bottom:1.1rem">
            <div style="min-width:0">
                <div class="ck-hub__title">Pipeline de última milla</div>
                <div class="ck-hub__sub">El flujo de los envíos por estado. Las tarjetas en rojo tienen la ventana comprometida vencida.</div>
            </div>
            <div class="ck-hub__aside">
                <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ number_format($totalActive) }} envíos activos</span>
                @if($totalLate > 0)
                    <span class="ck-pill ck-pill--danger"><span class="ck-pill__dot"></span>{{ $totalLate }} atrasados visibles</span>
                @endif
            </div>
        </div>

        <div class="ck-pipeline">
            @foreach($cols as $col)
                <div class="ck-col">
                    <div class="ck-col__head">
                        <span>{{ $col['label'] }}</span>
                        <span class="ck-col__n">{{ number_format($col['count']) }}</span>
                    </div>
                    <div class="ck-col__body">
                        @forelse($col['cards'] as $card)
                            <a href="{{ $this->shipmentUrl($card['id']) }}" wire:navigate
                               class="ck-ticket ck-ticket--{{ $card['late'] ? 'late' : $col['tone'] }}">
                                <div class="ck-ticket__ref">{{ $card['ref'] }}</div>
                                <div class="ck-ticket__title">{{ $card['customer'] }}</div>
                                <div class="ck-ticket__meta">{{ $card['window'] ?? 'Sin ventana' }}{{ $card['vehicle_plate'] ? ' · '.$card['vehicle_plate'] : '' }}</div>
                            </a>
                        @empty
                            <div class="ck-ticket__ref" style="padding:.5rem;text-align:center">Sin envíos</div>
                        @endforelse

                        @if($col['count'] > count($col['cards']))
                            <div class="ck-ticket__ref" style="padding:.45rem;text-align:center">+{{ number_format($col['count'] - count($col['cards'])) }} más</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
