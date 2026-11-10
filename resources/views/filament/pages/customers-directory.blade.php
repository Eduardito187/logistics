{{--
    Directorio de clientes (Fase 4 CRM) — puerta de entrada al Cliente 360. Lista los clientes (derivados
    de sus envíos) con su actividad y una búsqueda por nombre/teléfono/documento; cada fila enlaza a la
    ficha 360. Capa `.distrack-cockpit`, español directo. NUNCA UUID visible.
--}}
<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Clientes</div>
                    <div class="ck-hub__sub">{{ count($this->customers) }} clientes · ordenados por actividad reciente</div>
                </div>
            </div>
            <div class="ck-hub__aside" style="min-width:260px">
                <input type="text" id="customers-dir-search" aria-label="Buscar clientes" wire:model.live.debounce.400ms="search" placeholder="Buscar por nombre, teléfono o documento…"
                       style="width:100%;background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.6rem .9rem;font:inherit">
            </div>
        </div>

        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">Directorio</div>
            <div class="ck-events" style="margin-top:.6rem" wire:loading.class="ck-loading">
                @forelse($this->customers as $c)
                    <div class="ck-event" wire:key="cust-{{ md5($c['name']) }}">
                        <span class="ck-event__ic"></span>
                        <span class="ck-event__tx">
                            <a href="{{ $this->profileUrl($c['name']) }}" wire:navigate class="ck-link">{{ $c['name'] }}</a>
                            <small>
                                {{ $c['shipments'] }} {{ $c['shipments'] === 1 ? 'envío' : 'envíos' }}
                                @if($c['phone'])· {{ $c['phone'] }}@endif
                                @if($c['last'])· últ. {{ $c['last'] }}@endif
                            </small>
                        </span>
                        <a href="{{ $this->profileUrl($c['name']) }}" wire:navigate class="ck-pill ck-pill--agent">Ver ficha →</a>
                    </div>
                @empty
                    <div class="ck-empty">
                        <div class="ck-empty__title">Sin clientes</div>
                        <div class="ck-empty__hint">{{ trim($search) !== '' ? 'Ningún cliente coincide con la búsqueda.' : 'Todavía no hay clientes con envíos registrados.' }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
