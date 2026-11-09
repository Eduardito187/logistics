{{--
    Directorio de socios comerciales (CRM) — transportistas y vendedores en un solo lugar, con tipo,
    documento (NIT), estado y contacto, enlazando a la ficha 360 de cada uno. Capa `.distrack-cockpit`,
    español directo. NUNCA UUID visible.
--}}
@php
    $c = $this->counts;
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Socios comerciales</div>
                    <div class="ck-hub__sub">Transportistas y vendedores — gestionados juntos como lo que son: socios externos</div>
                </div>
            </div>
            <div class="ck-hub__aside" style="min-width:260px">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Buscar por nombre o NIT…"
                       style="width:100%;background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.6rem .9rem;font:inherit">
            </div>
        </div>

        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Transportistas</div>
                <div class="ck-stat__value">{{ number_format($c['carriers']) }}</div>
            </div>
            <div class="ck-stat ck-stat--positive">
                <div class="ck-stat__label">Vendedores</div>
                <div class="ck-stat__value">{{ number_format($c['sellers']) }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Total</div>
                <div class="ck-stat__value">{{ number_format($c['carriers'] + $c['sellers']) }}</div>
            </div>
        </div>

        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">Directorio · {{ count($this->partners) }}</div>
            <div class="ck-events" style="margin-top:.6rem" wire:loading.class="ck-loading">
                @forelse($this->partners as $pt)
                    <div class="ck-event" wire:key="partner-{{ md5($pt['type'].$pt['name']) }}">
                        <span class="ck-event__ic {{ $pt['active'] ? '' : 'ck-event__ic--red' }}"></span>
                        <span class="ck-event__tx">
                            @if($pt['url'])<a href="{{ $pt['url'] }}" wire:navigate class="ck-link">{{ $pt['name'] }}</a>@else{{ $pt['name'] }}@endif
                            <small>
                                @if($pt['document'])NIT {{ $pt['document'] }}@endif
                                @if($pt['document'] && $pt['contact']) · @endif
                                @if($pt['contact']){{ $pt['contact'] }}@endif
                                @if(! $pt['active']) · inactivo @endif
                            </small>
                        </span>
                        <span class="ck-pill {{ $pt['tone'] ? 'ck-pill--'.$pt['tone'] : '' }}">{{ $pt['type'] }}</span>
                    </div>
                @empty
                    <div class="ck-empty">
                        <div class="ck-empty__title">Sin socios</div>
                        <div class="ck-empty__hint">{{ trim($search) !== '' ? 'Nadie coincide con la búsqueda.' : 'No hay transportistas ni vendedores cargados.' }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
