{{--
    Directorio unificado de personas (CRM) — choferes, ayudantes y usuarios en un solo lugar, con su tipo,
    documento, contacto y estado, enlazando a cada ficha. Capa `.distrack-cockpit`, español directo. El
    dominio sigue separado; se unifica la experiencia. NUNCA UUID visible.
--}}
@php
    $c = $this->counts;
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Personas</div>
                    <div class="ck-hub__sub">Choferes, ayudantes y usuarios — gestionados como lo que son: personas</div>
                </div>
            </div>
            <div class="ck-hub__aside" style="min-width:260px">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Buscar por nombre, documento, teléfono o email…"
                       style="width:100%;background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.6rem .9rem;font:inherit">
            </div>
        </div>

        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Choferes</div>
                <div class="ck-stat__value">{{ number_format($c['drivers']) }}</div>
            </div>
            <div class="ck-stat ck-stat--warning">
                <div class="ck-stat__label">Ayudantes</div>
                <div class="ck-stat__value">{{ number_format($c['helpers']) }}</div>
            </div>
            <div class="ck-stat ck-stat--positive">
                <div class="ck-stat__label">Usuarios</div>
                <div class="ck-stat__value">{{ number_format($c['users']) }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Total</div>
                <div class="ck-stat__value">{{ number_format($c['drivers'] + $c['helpers'] + $c['users']) }}</div>
            </div>
        </div>

        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">Directorio · {{ count($this->people) }}</div>
            <div class="ck-events" style="margin-top:.6rem" wire:loading.class="ck-loading">
                @forelse($this->people as $p)
                    <div class="ck-event" wire:key="person-{{ md5($p['type'].$p['name']) }}">
                        <span class="ck-event__ic {{ $p['active'] ? '' : 'ck-event__ic--red' }}"></span>
                        <span class="ck-event__tx">
                            @if($p['url'])<a href="{{ $p['url'] }}" wire:navigate class="ck-link">{{ $p['name'] }}</a>@else{{ $p['name'] }}@endif
                            <small>
                                @if($p['document'])CI {{ $p['document'] }}@endif
                                @if($p['document'] && $p['contact']) · @endif
                                @if($p['contact']){{ $p['contact'] }}@endif
                                @if(! $p['active']) · inactivo @endif
                            </small>
                        </span>
                        <span class="ck-pill {{ $p['tone'] ? 'ck-pill--'.$p['tone'] : '' }}">{{ $p['type'] }}</span>
                    </div>
                @empty
                    <div class="ck-empty">
                        <div class="ck-empty__title">Sin personas</div>
                        <div class="ck-empty__hint">{{ trim($search) !== '' ? 'Nadie coincide con la búsqueda.' : 'No hay choferes, ayudantes ni usuarios cargados.' }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
