{{--
    Pipeline de pedidos (DisTrack OS, Fase 3 — workflows visibles). Tablero por estado del ciclo de vida
    de la orden: columnas (pendiente de pago → pagado → en preparación → cancelado) con tarjetas clickeables
    al hub del pedido. Datos reales y tolerantes vía App\Support\Cockpit\OrderPipeline ($this->columns).
    Capa `.distrack-cockpit`. Refresco por wire:poll. El gemelo del pipeline de envíos para comercio.
--}}
<x-filament-panels::page>
    @php
        $cols = $this->columns;
        $live = 0;
        foreach ($cols as $col) {
            if (in_array($col['status'], ['pending', 'paid', 'fulfilling'], true)) {
                $live += $col['count'];
            }
        }
    @endphp

    <div class="distrack-cockpit" wire:poll.60s>
        <div class="ck-hub__head" style="margin-bottom:1.1rem">
            <div style="min-width:0">
                <div class="ck-hub__title">Pipeline de pedidos</div>
                <div class="ck-hub__sub">El flujo de las órdenes de comercio por estado, del pago a la preparación.</div>
            </div>
            <div class="ck-hub__aside">
                <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ number_format($live) }} pedidos en curso</span>
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
                            <a href="{{ $this->orderUrl($card['id']) }}" wire:navigate
                               class="ck-ticket ck-ticket--{{ $col['tone'] }}">
                                <div class="ck-ticket__ref">{{ $card['ref'] }}</div>
                                <div class="ck-ticket__title">{{ $card['total'] }}</div>
                                <div class="ck-ticket__meta">{{ $card['meta'] }}</div>
                            </a>
                        @empty
                            <div class="ck-ticket__ref" style="padding:.5rem;text-align:center">Sin pedidos</div>
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
