{{--
    Cliente 360 (Fase 4 CRM) — cockpit de detalle del cliente. Agrega identidad + métricas (pedidos,
    envíos, efectividad, gasto, satisfacción), sus pedidos (enlazados a su hub), sus envíos, sus
    calificaciones, sus direcciones en un mapa Leaflet (self-host, CERO CDN), y un muro de NOTAS y
    seguimientos editable. Datos reales y tolerantes vía App\Support\Cockpit\CustomerHub ($this->hub).
    Capa `.distrack-cockpit`. Español directo (página cockpit). NUNCA UUID visible.
--}}
@php
    use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
    use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

    $h = $this->hub;
    $id = $h['identity'] ?? null;
    $m = $h['metrics'] ?? null;
    $hasCustomer = $this->customer !== null && $m !== null && (($m['shipments'] ?? 0) > 0 || ($m['orders'] ?? 0) > 0);

    $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
    $money = static function (?int $cents, string $c = 'BOB') use ($sym): string {
        return $cents === null ? '—' : $sym($c).' '.number_format($cents / 100, 2);
    };

    $shipStatusMap = [
        'RECEIVED' => ['Recibido', ''],
        'PLANNED' => ['Planificado', 'agent'],
        'IN_EXECUTION' => ['En ejecución', 'warning'],
        'DELIVERED' => ['Entregado', 'positive'],
        'FAILED' => ['Fallido', 'danger'],
        'CANCELLED' => ['Cancelado', ''],
    ];
    $orderStatusMap = [
        'pending' => ['Pendiente', ''],
        'paid' => ['Pagado', 'positive'],
        'fulfilling' => ['En preparación', 'agent'],
        'cancelled' => ['Cancelado', 'danger'],
    ];
    $effTone = static fn (?int $e): string => $e === null ? '' : ($e >= 90 ? 'positive' : ($e >= 75 ? 'warning' : 'danger'));
    $stars = static fn (float $r): string => str_repeat('★', (int) round($r)).str_repeat('☆', max(0, 5 - (int) round($r)));
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit" x-data="{ t: 'summary' }">
        @if($hasCustomer)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $id['name'] }}</div>
                        <div class="ck-hub__sub">
                            {{ $m['orders'] }} {{ $m['orders'] === 1 ? 'pedido' : 'pedidos' }} ·
                            {{ $m['shipments'] }} {{ $m['shipments'] === 1 ? 'envío' : 'envíos' }} ·
                            {{ $money($m['spend_cents'], $m['currency']) }} histórico
                        </div>
                        <div class="ck-hub__pills">
                            @if($id['document'])<span class="ck-pill ck-mono">CI {{ $id['document'] }}</span>@endif
                            @if($id['phone'])<span class="ck-pill ck-mono">{{ $id['phone'] }}</span>@endif
                            @if($id['email'])<span class="ck-pill">{{ $id['email'] }}</span>@endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    @if($m['active_shipments'] > 0)
                        <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ $m['active_shipments'] }} envíos activos</span>
                    @else
                        <span class="ck-pill">Sin envíos activos</span>
                    @endif
                    @if($m['last_activity'])<span class="ck-hub__sub">Última actividad: {{ $m['last_activity'] }}</span>@endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat">
                    <div class="ck-stat__label">Gasto histórico</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($m['spend_cents'], $m['currency']) }}</div>
                    <div class="ck-stat__hint">{{ $m['orders'] }} {{ $m['orders'] === 1 ? 'pedido' : 'pedidos' }}</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Envíos</div>
                    <div class="ck-stat__value">{{ $m['shipments'] }}</div>
                    <div class="ck-stat__hint">{{ $m['active_shipments'] }} activos · {{ $m['delivered'] }} entregados</div>
                </div>
                <div class="ck-stat {{ $effTone($m['effectiveness']) ? 'ck-stat--'.$effTone($m['effectiveness']) : '' }}">
                    <div class="ck-stat__label">Efectividad</div>
                    <div class="ck-stat__value">{{ $m['effectiveness'] !== null ? $m['effectiveness'].'%' : '—' }}</div>
                    <div class="ck-stat__hint">{{ $m['failed'] }} fallidos</div>
                </div>
                <div class="ck-stat {{ $m['satisfaction_avg'] !== null ? ($m['satisfaction_avg'] >= 4 ? 'ck-stat--positive' : ($m['satisfaction_avg'] >= 3 ? 'ck-stat--warning' : 'ck-stat--danger')) : '' }}">
                    <div class="ck-stat__label">Satisfacción</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $m['satisfaction_avg'] !== null ? $m['satisfaction_avg'].' / 5' : '—' }}</div>
                    <div class="ck-stat__hint">{{ $m['satisfaction_count'] }} {{ $m['satisfaction_count'] === 1 ? 'encuesta' : 'encuestas' }}</div>
                </div>
            </div>

            {{-- ===== TABS ===== --}}
            <div class="ck-tabs">
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'orders' }" @click="t = 'orders'">Pedidos<span class="ck-tab__n">{{ count($h['orders']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'shipments' }" @click="t = 'shipments'">Envíos<span class="ck-tab__n">{{ count($h['shipments']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'satisfaction' }" @click="t = 'satisfaction'">Satisfacción<span class="ck-tab__n">{{ count($h['satisfaction']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'map' }" @click="t = 'map'">Direcciones<span class="ck-tab__n">{{ count($h['addresses']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'notes' }" @click="t = 'notes'">Notas<span class="ck-tab__n">{{ count($this->notes) }}</span></button>
            </div>

            {{-- ===== PANEL · RESUMEN ===== --}}
            <div x-show="t === 'summary'">
                <div class="ck-grid ck-grid--2 ck-section">
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Contacto</div>
                        <div class="ck-card__title" style="font-size:1.1rem">{{ $id['name'] }}</div>
                        <dl class="ck-dl" style="margin-top:1rem">
                            <dt>Documento</dt><dd class="ck-mono">{{ $id['document'] ?: '—' }}</dd>
                            <dt>Teléfono</dt><dd class="ck-mono">{{ $id['phone'] ?: '—' }}</dd>
                            <dt>Email</dt><dd>{{ $id['email'] ?: '—' }}</dd>
                            <dt>Direcciones</dt><dd>{{ count($h['addresses']) }}</dd>
                        </dl>
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Actividad reciente</div>
                        <div class="ck-events" style="margin-top:.6rem">
                            @forelse(array_slice($h['shipments'], 0, 6) as $sh)
                                @php [$l, $tone] = $shipStatusMap[strtoupper($sh['status'])] ?? [ucfirst(strtolower($sh['status'])), '']; @endphp
                                <div class="ck-event">
                                    <span class="ck-event__ic"></span>
                                    <span class="ck-event__tx"><a href="{{ ShipmentResource::getUrl('view', ['record' => $sh['id']]) }}" wire:navigate class="ck-link">{{ $sh['reference'] }}</a></span>
                                    <span class="ck-pill {{ $tone ? 'ck-pill--'.$tone : '' }}">{{ $l }}</span>
                                </div>
                            @empty
                                <div class="ck-empty"><div class="ck-empty__title">Sin envíos</div><div class="ck-empty__hint">Este cliente no tiene envíos registrados.</div></div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · PEDIDOS ===== --}}
            <div x-show="t === 'orders'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Pedidos del cliente</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($h['orders'] as $o)
                            @php [$l, $tone] = $orderStatusMap[strtolower($o['status'])] ?? [ucfirst(strtolower($o['status'])), '']; @endphp
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    <a href="{{ OrderResource::getUrl('view', ['record' => $o['id']]) }}" wire:navigate class="ck-link">{{ $money($o['total_cents'], $o['currency']) }}</a>
                                    @if($o['created'])<small>{{ $o['created'] }}</small>@endif
                                </span>
                                <span class="ck-pill {{ $tone ? 'ck-pill--'.$tone : '' }}">{{ $l }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin pedidos comerciales</div><div class="ck-empty__hint">No hay pedidos del OMS a nombre de este cliente.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · ENVÍOS ===== --}}
            <div x-show="t === 'shipments'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Envíos del cliente</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($h['shipments'] as $sh)
                            @php [$l, $tone] = $shipStatusMap[strtoupper($sh['status'])] ?? [ucfirst(strtolower($sh['status'])), '']; @endphp
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    <a href="{{ ShipmentResource::getUrl('view', ['record' => $sh['id']]) }}" wire:navigate class="ck-link">{{ $sh['reference'] }}</a>
                                    @if($sh['delivery_date'])<small>Entrega: {{ $sh['delivery_date'] }}</small>@endif
                                </span>
                                <span class="ck-pill {{ $tone ? 'ck-pill--'.$tone : '' }}">{{ $l }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin envíos</div><div class="ck-empty__hint">Este cliente no tiene envíos registrados.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · SATISFACCIÓN ===== --}}
            <div x-show="t === 'satisfaction'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Calificaciones del cliente</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($h['satisfaction'] as $s)
                            <div class="ck-event">
                                <span class="ck-event__ic {{ $s['rating'] >= 4 ? 'ck-event__ic--green' : ($s['rating'] <= 2 ? 'ck-event__ic--red' : 'ck-event__ic--amber') }}"></span>
                                <span class="ck-event__tx">
                                    <span style="letter-spacing:2px;color:var(--ck-amber,#f0b429)">{{ $stars((float) $s['rating']) }}</span>
                                    @if($s['comment'])<small>{{ $s['comment'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">{{ $s['when'] ?: '' }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin calificaciones</div><div class="ck-empty__hint">Este cliente aún no respondió encuestas de satisfacción.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · DIRECCIONES (mapa) ===== --}}
            <div x-show="t === 'map'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Direcciones de entrega</div>
                    @if(count($this->mapPoints) > 0)
                        <div id="customer-profile-map" style="height:380px;width:100%;border-radius:12px;overflow:hidden;margin-top:.6rem;z-index:0"></div>
                    @endif
                    <div class="ck-events" style="margin-top:.8rem">
                        @forelse($h['addresses'] as $addr)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ $addr['text'] }}
                                    @if($addr['reference'])<small>{{ $addr['reference'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">{{ $addr['count'] }} {{ $addr['count'] === 1 ? 'envío' : 'envíos' }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin direcciones</div><div class="ck-empty__hint">No hay direcciones de entrega registradas.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · NOTAS Y SEGUIMIENTOS ===== --}}
            <div x-show="t === 'notes'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Nueva nota o seguimiento</div>
                    <div style="margin-top:.7rem;display:flex;flex-direction:column;gap:.6rem">
                        <textarea wire:model="noteBody" rows="2" placeholder="Escribí una nota, un acuerdo o un recordatorio…"
                                  style="width:100%;background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.7rem .9rem;font:inherit;resize:vertical"></textarea>
                        <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                            <select wire:model="noteKind" class="ck-input"
                                    style="background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.5rem .7rem;font:inherit">
                                <option value="note">Nota</option>
                                <option value="task">Tarea (con vencimiento)</option>
                            </select>
                            <input type="date" wire:model="noteDue"
                                   style="background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.5rem .7rem;font:inherit">
                            <button type="button" wire:click="addNote" wire:loading.attr="disabled" class="ck-btn ck-btn--primary"
                                    style="background:var(--ck-red,#e30613);color:#fff;border:0;border-radius:10px;padding:.55rem 1.1rem;font:inherit;font-weight:600;cursor:pointer">
                                Guardar
                            </button>
                        </div>
                    </div>
                </div>

                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Muro de actividad</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($this->notes as $n)
                            <div class="ck-event" wire:key="note-{{ $n['id'] }}">
                                <span class="ck-event__ic {{ $n['kind'] === 'task' ? ($n['done'] ? 'ck-event__ic--green' : 'ck-event__ic--amber') : '' }}"></span>
                                <span class="ck-event__tx" style="{{ $n['done'] ? 'opacity:.55;text-decoration:line-through' : '' }}">
                                    {{ $n['body'] }}
                                    <small>
                                        {{ $n['author'] ?: 'Oficina' }}{{ $n['when'] ? ' · '.$n['when'] : '' }}
                                        @if($n['kind'] === 'task' && $n['due'])· vence {{ $n['due'] }}@endif
                                    </small>
                                </span>
                                <span style="display:inline-flex;gap:.5rem;align-items:center">
                                    @if($n['kind'] === 'task')
                                        <button type="button" wire:click="toggleTask({{ $n['id'] }})" class="ck-link" style="background:none;border:0;cursor:pointer">
                                            {{ $n['done'] ? 'Reabrir' : 'Marcar hecha' }}
                                        </button>
                                    @endif
                                    <button type="button" wire:click="deleteNote({{ $n['id'] }})" class="ck-link" style="background:none;border:0;cursor:pointer;color:var(--ck-danger,#e5484d)">Borrar</button>
                                </span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin actividad</div><div class="ck-empty__hint">Agregá la primera nota o seguimiento de este cliente.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Cliente no disponible</div>
                <div class="ck-empty__hint">No se seleccionó un cliente o no hay registros a su nombre.</div>
            </div>
        @endif
    </div>

    @assets
        {{-- Leaflet vendorizado localmente: CERO dependencias externas en runtime (servido desde self). --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
    @endassets

    @script
    <script>
        const points = @js($this->mapPoints);
        const render = () => {
            const el = document.getElementById('customer-profile-map');
            if (!el || el.dataset.ready || !points.length || !window.L) return;
            el.dataset.ready = '1';
            const map = L.map(el);
            window.DismacMaps.tileLayer(L, {
                url: @js(config('maps.tile_url')),
                fallbackUrl: @js(config('maps.tile_fallback_url')),
                attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
            }).addTo(map);
            const markers = points.map((p) => {
                const mk = L.marker([p.lat, p.lng]).addTo(map);
                if (p.text) mk.bindPopup(p.text);
                return mk;
            });
            if (markers.length === 1) {
                map.setView([points[0].lat, points[0].lng], 15);
            } else {
                map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
            }
            setTimeout(() => map.invalidateSize(), 250);
        };
        // El mapa vive en una pestaña oculta: se inicializa al mostrarla (y como respaldo, al cargar).
        document.addEventListener('click', (e) => { if (e.target.closest('.ck-tab')) setTimeout(render, 120); });
        setTimeout(render, 300);
    </script>
    @endscript
</x-filament-panels::page>
