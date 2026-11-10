{{--
    Cockpit de liquidaciones (CRM financiero) — transportistas y vendedores en una sola vista, con KPIs por
    estado, total por pagar de cada frente y lista reciente enlazada a cada ficha. Capa `.distrack-cockpit`,
    español directo. NUNCA UUID visible.
--}}
@php
    $b = $this->board;
    $carrier = $b['carrier'];
    $seller = $b['seller'];

    $estados = [
        'submitted' => 'Enviadas', 'approved' => 'Aprobadas', 'paid' => 'Pagadas', 'disputed' => 'Objetadas',
        'draft' => 'Borrador', 'confirmed' => 'Confirmadas',
    ];
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Liquidaciones</div>
                    <div class="ck-hub__sub">Pagos a transportistas y vendedores — una sola vista financiera</div>
                </div>
            </div>
        </div>

        {{-- ─────────────── Transportistas ─────────────── --}}
        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">
                Transportistas
                @if($carrierUrl = $this->carrierIndexUrl())
                    · <a href="{{ $carrierUrl }}" wire:navigate class="ck-link">ver todas</a>
                @endif
            </div>

            <div class="ck-grid ck-grid--4" style="margin-top:.6rem">
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Por pagar (aprobadas)</div>
                    <div class="ck-stat__value">{{ $carrier['payable_label'] }}</div>
                </div>
                <div class="ck-stat ck-stat--warning">
                    <div class="ck-stat__label">Enviadas</div>
                    <div class="ck-stat__value">{{ number_format($carrier['counts']['submitted'] ?? 0) }}</div>
                </div>
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Pagadas</div>
                    <div class="ck-stat__value">{{ number_format($carrier['counts']['paid'] ?? 0) }}</div>
                </div>
                <div class="ck-stat {{ ($carrier['disputed_count'] ?? 0) > 0 ? 'ck-stat--danger' : '' }}">
                    <div class="ck-stat__label">Objetadas</div>
                    <div class="ck-stat__value">{{ number_format($carrier['disputed_count'] ?? 0) }}</div>
                </div>
            </div>

            <div class="ck-events" style="margin-top:.9rem">
                @forelse($carrier['recent'] as $r)
                    <div class="ck-event" wire:key="car-{{ md5($r['name'].$r['subtitle'].$r['amount']) }}">
                        <span class="ck-event__ic {{ $r['tone'] === 'danger' ? 'ck-event__ic--red' : '' }}"></span>
                        <span class="ck-event__tx">
                            @if($r['url'])<a href="{{ $r['url'] }}" wire:navigate class="ck-link">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif
                            <small>{{ $r['subtitle'] }} · {{ $r['amount'] }}</small>
                        </span>
                        <span class="ck-pill {{ $r['tone'] ? 'ck-pill--'.$r['tone'] : '' }}">{{ $estados[$r['status']] ?? $r['status'] }}</span>
                    </div>
                @empty
                    <div class="ck-empty">
                        <div class="ck-empty__title">Sin liquidaciones de transportistas</div>
                        <div class="ck-empty__hint">Las liquidaciones llegan por la puerta de integraciones cuando el transportista las envía.</div>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ─────────────── Vendedores ─────────────── --}}
        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">
                Vendedores
                @if($sellerUrl = $this->sellerIndexUrl())
                    · <a href="{{ $sellerUrl }}" wire:navigate class="ck-link">ver todas</a>
                @endif
            </div>

            <div class="ck-grid ck-grid--4" style="margin-top:.6rem">
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Por pagar (confirmadas)</div>
                    <div class="ck-stat__value">{{ $seller['payable_label'] }}</div>
                </div>
                <div class="ck-stat ck-stat--warning">
                    <div class="ck-stat__label">Borrador</div>
                    <div class="ck-stat__value">{{ number_format($seller['counts']['draft'] ?? 0) }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Confirmadas</div>
                    <div class="ck-stat__value">{{ number_format($seller['counts']['confirmed'] ?? 0) }}</div>
                </div>
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Pagadas</div>
                    <div class="ck-stat__value">{{ number_format($seller['counts']['paid'] ?? 0) }}</div>
                </div>
            </div>

            <div class="ck-events" style="margin-top:.9rem">
                @forelse($seller['recent'] as $r)
                    <div class="ck-event" wire:key="sel-{{ md5($r['name'].$r['subtitle'].$r['amount']) }}">
                        <span class="ck-event__ic"></span>
                        <span class="ck-event__tx">
                            @if($r['url'])<a href="{{ $r['url'] }}" wire:navigate class="ck-link">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif
                            <small>{{ $r['subtitle'] }} · {{ $r['amount'] }}</small>
                        </span>
                        <span class="ck-pill {{ $r['tone'] ? 'ck-pill--'.$r['tone'] : '' }}">{{ $estados[$r['status']] ?? $r['status'] }}</span>
                    </div>
                @empty
                    <div class="ck-empty">
                        <div class="ck-empty__title">Sin liquidaciones de vendedores</div>
                        <div class="ck-empty__hint">Se arman desde la lista de liquidaciones de vendedores, agregando los despachos del periodo.</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
