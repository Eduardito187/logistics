{{--
    Torre de control — sala de operaciones (cockpit) sobre superficie "andén" clara (ver theme.css
    `.distrack-cockpit` y los componentes x-cockpit.*). Composición: saludo + pulso de KPIs, la ruta
    señalada que necesita acción, el resumen de operaciones (en riesgo / causas / propuestas a aprobar)
    y el mini-mapa de la red en vivo. Datos reales de App\Support\Cockpit\*; refresco por wire:poll.
    El mapa usa Leaflet AUTO-HOSPEDADO (/vendor/leaflet) y el tileserver propio: CERO CDN.
--}}
@php
    $tiles = collect($this->pulse());
    $byKey = $tiles->keyBy('key');
    $brief = $this->brief();
    $flagged = $this->flagged();
    $map = $this->mapData();

    $active = (int) ($byKey['active_shipments']['value'] ?? 0);

    // Propuestas accionables aún no aprobadas en esta sesión de pantalla = "aprobaciones" pendientes.
    $pendingApprovals = collect($brief['proposals'] ?? [])
        ->filter(fn ($p) => ($p['actionable'] ?? false) && ! in_array($p['id'] ?? '', $this->approvedProposals, true))
        ->count();

    $newShipmentUrl = $this->newShipmentUrl();
    $liveMapUrl = $this->liveMapUrl();

    $heroTitle = $flagged
        ? trim((string) (($flagged['origin'] ?? '') !== '' ? $flagged['origin'].' → ' : '').($flagged['destination'] ?? ''))
        : null;
    if ($flagged && ($heroTitle === '' || $heroTitle === '→')) {
        $heroTitle = $flagged['shipment_ref'] ?? __('Envío señalado');
    }

    // Minutos → legible (16d 10h / 2h 05m / 45m).
    $fmtMins = function (?int $m): ?string {
        if ($m === null) {
            return null;
        }
        $m = abs($m);
        if ($m < 60) {
            return $m.'m';
        }
        if ($m < 1440) {
            return intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
        }
        return intdiv($m, 1440).'d '.intdiv($m % 1440, 60).'h';
    };
    // ISO → "19 oct 09:00" (tolerante: si no parsea, devuelve el texto tal cual).
    $fmtTime = function (?string $iso): ?string {
        if (! $iso) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM HH:mm');
        } catch (\Throwable) {
            return $iso;
        }
    };
@endphp

<x-filament-panels::page>
    <x-cockpit.surface wire:poll.30s>

        {{-- ====================== SALUDO / ENCABEZADO ====================== --}}
        <x-cockpit.greeting
            :eyebrow="strtoupper($this->todayLabel())"
            :title="$this->greeting().', '.$this->greetingName()"
            :subtitle="__('Esto es lo que necesita tu atención en la operación de hoy.')"
        >
            <x-slot:actions>
                @if ($this->hasOpsScope())
                    <x-cockpit.pill tone="agent">{{ __('Monitoreando') }} {{ number_format($active) }}</x-cockpit.pill>
                    @if ($pendingApprovals > 0)
                        <x-cockpit.pill tone="warning">{{ trans_choice('{1}:count aprobación|[2,*]:count aprobaciones', $pendingApprovals, ['count' => $pendingApprovals]) }}</x-cockpit.pill>
                    @endif
                    @if ($newShipmentUrl)
                        <a href="{{ $newShipmentUrl }}" class="ck-btn ck-btn--brand" wire:navigate>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            {{ __('Nuevo envío') }}
                        </a>
                    @endif
                @endif
            </x-slot:actions>
        </x-cockpit.greeting>

        @if (! $this->hasOpsScope())
            {{-- Rol de campo: home adaptado a su rol (Fase 4). Chofer → su día; Tienda → su nodo; el resto,
                 accesos a sus secciones. Los homes son informativos: cada rol opera desde su navegación. --}}
            @php $home = $this->roleHome(); @endphp
            @if ($home && $home['type'] === 'driver')
                @include('filament.cockpit.home-driver', ['h' => $home['data']])
            @elseif ($home && $home['type'] === 'store')
                @include('filament.cockpit.home-store', ['h' => $home['data']])
            @elseif ($home && $home['type'] === 'picker')
                @include('filament.cockpit.home-picker', ['h' => $home['data']])
            @elseif ($home && $home['type'] === 'carrier')
                @include('filament.cockpit.home-carrier', ['h' => $home['data']])
            @else
                <x-cockpit.card class="mt-5">
                    <p class="ck-greeting__sub">
                        {{ __('Tu tablero de operaciones está adaptado a tu rol. Usá la navegación para llegar a tus tableros y tareas.') }}
                    </p>
                </x-cockpit.card>
            @endif
        @else

            {{-- Indicador de tiempo real: refleja el estado REAL de la conexión WebSocket (Echo/Soketi). En
                 vivo = verde con latido; si no hay push (Echo no conectó), queda el refresco por polling. --}}
            <div x-data="{ live: false, init() { const e = window.Echo; const c = e && e.connector && e.connector.pusher ? e.connector.pusher.connection : null; if (!c) return; this.live = c.state === 'connected'; c.bind('state_change', (s) => { this.live = s.current === 'connected'; }); } }"
                 x-init="init()" style="display:flex;justify-content:flex-end;margin-top:.6rem">
                <span class="ck-pill" :class="live ? 'ck-pill--positive' : ''" title="Estado de la conexión en vivo">
                    <span class="ck-pill__dot"></span><span x-text="live ? 'EN VIVO' : 'Sin conexión en vivo'"></span>
                </span>
            </div>

            {{-- ====================== PULSO DE KPIs ====================== --}}
            <div class="ck-pulse mt-5">
                @foreach ($tiles as $tile)
                    <x-cockpit.stat
                        :label="__($tile['label'] ?? '')"
                        :value="number_format((int) ($tile['value'] ?? 0))"
                        :tone="$tile['tone'] ?? 'neutral'"
                        :delta="$tile['delta'] ?? null"
                        :trend="$tile['trend'] ?? 'flat'"
                        :delta-tone="$tile['delta_tone'] ?? 'neutral'"
                        :hint="$tile['hint'] ?? null"
                    />
                @endforeach
            </div>

            {{-- ====================== HERO + BRIEF ====================== --}}
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mt-4">

                {{-- Ruta señalada (2/3) --}}
                <div class="xl:col-span-2">
                    @if ($flagged)
                        <x-cockpit.card raised>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="ck-card__eyebrow" style="color: var(--ck-red)">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 2L1 21h22L12 2zm0 6l6.9 11.9H5.1L12 8zm-1 3v4h2v-4h-2zm0 5v2h2v-2h-2z"/></svg>
                                        {{ __('Ruta señalada por el agente') }}
                                    </p>
                                    <h2 class="ck-hero__route mt-1">{{ $heroTitle }}</h2>
                                    <p class="ck-greeting__sub mt-1">
                                        <span class="ck-mono">{{ $flagged['shipment_ref'] ?? '—' }}</span>
                                        @if (! empty($flagged['driver_name'])) · {{ $flagged['driver_name'] }} @endif
                                        @if (! empty($flagged['vehicle_label'])) · <span class="ck-mono">{{ $flagged['vehicle_label'] }}</span> @endif
                                    </p>
                                </div>
                                <x-cockpit.pill tone="danger" :dot="false">{{ $flagged['flagged_reason'] ?? __('En riesgo') }}</x-cockpit.pill>
                            </div>

                            {{-- Compartir seguimiento: enlace PÚBLICO del envío (página t/{token}) para mandar al cliente. --}}
                            @php $shareUrl = ! empty($flagged['shipment_id']) ? $this->shareTrackingUrl($flagged['shipment_id']) : null; @endphp
                            @if ($shareUrl)
                                <div class="mt-3 flex items-center gap-2" x-data="{ copied: false }">
                                    <button type="button" class="ck-btn ck-btn--ghost"
                                        x-on:click="navigator.clipboard?.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 1800) }).catch(() => {})">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                                        <span x-show="!copied">{{ __('Share tracking') }}</span>
                                        <span x-show="copied" x-cloak>{{ __('Link copied') }}</span>
                                    </button>
                                    <a href="{{ $shareUrl }}" target="_blank" rel="noopener" class="ck-link">{{ __('Open link') }}</a>
                                </div>
                            @endif

                            {{-- Tira de telemetría (solo datos reales: nada de temperatura reefer ni combustible vivo). --}}
                            <div class="ck-telemetry mt-4">
                                <x-cockpit.metric
                                    :label="__('Velocidad')"
                                    :value="isset($flagged['speed_kph']) ? number_format((float) $flagged['speed_kph'], 0).' km/h' : '—'"
                                />
                                <x-cockpit.metric
                                    :label="__('Estado')"
                                    :value="($flagged['moving'] ?? false) ? __('En movimiento') : __('Detenido')"
                                />
                                <x-cockpit.metric
                                    :label="__('Ventana')"
                                    :value="$fmtTime($flagged['window_start'] ?? null) ? ($fmtTime($flagged['window_start']).' – '.($fmtTime($flagged['window_end'] ?? null) ?? '')) : '—'"
                                />
                                <x-cockpit.metric
                                    :label="__('ETA')"
                                    :value="isset($flagged['eta_delay_minutes']) && (int) $flagged['eta_delay_minutes'] > 0 ? ('+'.$fmtMins((int) $flagged['eta_delay_minutes']).' '.__('de retraso')) : ($fmtTime($flagged['eta'] ?? null) ?? '—')"
                                    :tone="isset($flagged['eta_delay_minutes']) && (int) $flagged['eta_delay_minutes'] > 0 ? 'danger' : null"
                                />
                            </div>

                            @if (! empty($flagged['stops']))
                                <div class="ck-divider"></div>
                                <x-cockpit.route-timeline :stops="$flagged['stops']" />
                            @endif
                        </x-cockpit.card>
                    @else
                        {{-- Nada señalado: la red va en plan. --}}
                        <x-cockpit.card raised>
                            <div class="flex items-center gap-3">
                                <span style="display:inline-flex;width:40px;height:40px;border-radius:12px;align-items:center;justify-content:center;background:var(--ck-green-bg);color:var(--ck-green)">
                                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                                </span>
                                <div>
                                    <h2 class="ck-card__title">{{ __('Sin rutas señaladas') }}</h2>
                                    <p class="ck-greeting__sub">{{ __('Cada entrega activa va en plan para su ventana.') }}</p>
                                </div>
                            </div>
                        </x-cockpit.card>
                    @endif
                </div>

                {{-- Resumen de operaciones (1/3) — agente de inteligencia derivada real --}}
                <div>
                    <x-cockpit.agent-card
                        :eyebrow="__('Resumen de operaciones')"
                        :confidence="($brief['at_risk_count'] ?? 0) > 0 ? ($brief['at_risk_count'].' '.__('en riesgo')) : __('en plan')"
                    >
                        <p class="ck-agentcard__headline">{{ $brief['headline'] ?? __('La red va en plan.') }}</p>

                        @if (! empty($brief['causes']))
                            <p class="ck-card__eyebrow mt-4">{{ __('Por qué — causas que contribuyen') }}</p>
                            <div class="mt-2 space-y-2.5">
                                @foreach ($brief['causes'] as $i => $cause)
                                    <x-cockpit.bar
                                        :label="__($cause['label'] ?? '')"
                                        :count="$cause['count'] ?? null"
                                        :weight="$cause['weight'] ?? 0"
                                        :tone="$i === 0 ? 'danger' : ($i === 1 ? 'warning' : 'agent')"
                                    />
                                @endforeach
                            </div>
                        @endif

                        @php $proposals = collect($brief['proposals'] ?? [])->filter(fn ($p) => $p['actionable'] ?? false)->values(); @endphp
                        @if ($proposals->isNotEmpty())
                            <p class="ck-card__eyebrow mt-4">{{ __('Propuesto — requiere tu aprobación') }}</p>
                            <div class="mt-1">
                                @foreach ($proposals as $p)
                                    @php $id = (string) ($p['id'] ?? ''); $done = in_array($id, $this->approvedProposals, true); @endphp
                                    <x-cockpit.proposal
                                        :label="__($p['label'] ?? '')"
                                        :meta="isset($p['count']) ? trans_choice('{1}:count ítem|[2,*]:count ítems', (int) $p['count'], ['count' => (int) $p['count']]) : null"
                                    >
                                        @if ($done)
                                            <x-cockpit.pill tone="positive" :dot="false">{{ __('Aprobada') }}</x-cockpit.pill>
                                        @else
                                            {{-- Confirmación en dos pasos antes de despachar los comandos reales (aprobar no es trivial:
                                                 notifica clientes / replanifica / libera cupo). Evita aprobaciones accidentales. --}}
                                            <div x-data="{ confirming: false }" class="ck-approve">
                                                <button type="button" class="ck-btn ck-btn--approve"
                                                    x-show="!confirming"
                                                    x-on:click="confirming = true">
                                                    {{ __('Aprobar') }}
                                                </button>
                                                <div x-show="confirming" x-cloak class="ck-approve__confirm">
                                                    <span class="ck-approve__ask">{{ __('Confirm?') }}</span>
                                                    <button type="button" class="ck-btn ck-btn--approve"
                                                        wire:click="approveProposal('{{ $id }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="approveProposal"
                                                        x-on:click="confirming = false">
                                                        <span wire:loading.remove wire:target="approveProposal">{{ __('Yes, approve') }}</span>
                                                        <span wire:loading wire:target="approveProposal">{{ __('Procesando…') }}</span>
                                                    </button>
                                                    <button type="button" class="ck-btn ck-btn--ghost" x-on:click="confirming = false">{{ __('Cancel') }}</button>
                                                </div>
                                            </div>
                                        @endif
                                    </x-cockpit.proposal>
                                @endforeach
                            </div>
                        @else
                            <div class="ck-divider"></div>
                            <p class="ck-greeting__sub">{{ __('No hay acciones esperando aprobación en este momento.') }}</p>
                        @endif
                    </x-cockpit.agent-card>
                </div>
            </div>

            {{-- ====================== TENDENCIAS (Fase 5 — datos que se ven) ====================== --}}
            @php $tr = $this->trends(); $statusMax = max(1, max(array_column($tr['status'], 'count'))); @endphp
            <div class="ck-grid ck-grid--2 ck-section">
                <x-cockpit.card :eyebrow="__('Carga por estado')" :title="__('Envíos en la red')">
                    <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:.9rem">
                        @foreach ($tr['status'] as $row)
                            @php $fill = in_array($row['tone'], ['red', 'amber', 'green'], true) ? 'ck-bar__fill--'.$row['tone'] : ''; @endphp
                            <div class="ck-bar">
                                <span class="ck-bar__label">{{ $row['label'] }}</span>
                                <span class="ck-bar__count">{{ number_format($row['count']) }}</span>
                                <span class="ck-bar__track"><span class="ck-bar__fill {{ $fill }}" style="width:{{ $row['count'] > 0 ? max(3, (int) round($row['count'] / $statusMax * 100)) : 0 }}%"></span></span>
                            </div>
                        @endforeach
                    </div>
                </x-cockpit.card>

                <x-cockpit.card :eyebrow="__('Últimos 14 días')" :title="__('Recibidos y entregados')">
                    <div style="margin-top:.6rem">
                        <x-cockpit.chart
                            type="area"
                            :series="$tr['received']['values']"
                            :labels="$tr['received']['labels']"
                            tone="agent"
                            :height="110"
                            :label="__('Recibidos')"
                            :value="number_format($tr['totals']['received'])"
                            unit="14d" />
                    </div>
                    <div style="margin-top:.9rem">
                        <x-cockpit.chart
                            type="bars"
                            :series="$tr['delivered']['values']"
                            :labels="$tr['delivered']['labels']"
                            tone="green"
                            :height="92"
                            :label="__('Entregados')"
                            :value="number_format($tr['totals']['delivered'])"
                            unit="14d" />
                    </div>
                </x-cockpit.card>
            </div>

            {{-- ====================== MAPA DE LA RED EN VIVO ====================== --}}
            <x-cockpit.card class="mt-4" :eyebrow="__('Red en vivo')" :title="__('Flota en movimiento')">
                {{-- Sintaxis de slot con name= (no `<x-slot:head>`): evita que el analizador HTML lo lea como
                     una etiqueta <head> y pida un <title> (falso positivo). --}}
                <x-slot name="head">
                    @if ($liveMapUrl)
                        <a href="{{ $liveMapUrl }}" class="ck-link" wire:navigate>{{ __('Abrir seguimiento en vivo') }} →</a>
                    @endif
                </x-slot>

                @if (! empty($map['positions']))
                    <div wire:ignore>
                        <div id="ct-network-map" class="ck-map"></div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-4" style="font-size:0.72rem;color:var(--ck-ink-3)">
                        <span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:999px;background:var(--ck-green);display:inline-block"></span>{{ __('En movimiento') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:999px;background:var(--ck-ink-3);display:inline-block"></span>{{ __('Detenido') }}</span>
                    </div>
                @else
                    {{-- Sin vehículos en vivo (o telemetría/tileserver no disponible): estado vacío elegante, no un recuadro en blanco. --}}
                    <div class="ck-map-empty">
                        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3z"/><path d="M9 3v15M15 6v15"/>
                        </svg>
                        <p class="ck-map-empty__title">{{ __('No hay vehículos en movimiento en este momento') }}</p>
                        <p class="ck-map-empty__hint">{{ __('Las posiciones en vivo aparecerán aquí cuando haya camiones en ruta.') }}</p>
                    </div>
                @endif
            </x-cockpit.card>

            {{-- Leaflet AUTO-HOSPEDADO + helper de teselas self-host (CERO CDN). --}}
            @assets
                <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
                <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
                <script src="{{ asset('js/dismac-maps.js') }}"></script>
                {{-- Ciclo de vida del polling/mapa/Echo en modo SPA (libera todo al salir). --}}
                <script src="{{ asset('js/dismac-live.js') }}"></script>
            @endassets

            @script
            <script>
                const mapEl = document.getElementById('ct-network-map');
                if (mapEl && window.L) {
                    const initial = @js($map);
                    // Sesión "en vivo": al salir con navegación SPA corta polling, debounces, Echo y mapa
                    // (antes se acumulaban un intervalo, un listener de Echo y un mapa por cada visita).
                    const live = window.DismacLive.session({ el: mapEl, component: $wire.__instance });

                    const map = L.map(mapEl, { zoomControl: true, attributionControl: true })
                        .setView(initial.center, 11);
                    live.onDispose(() => map.remove());

                    // Teselas SOLO del tileserver self-host (sin fallback a CDN público; política no-CDN).
                    window.DismacMaps.tileLayer(L, {
                        url: @js(config('maps.tile_url')),
                        attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                        maxZoom: initial.max_zoom,
                    }).addTo(map);

                    const layer = L.layerGroup().addTo(map);

                    const dot = (moving) => L.divIcon({
                        className: 'ct-fleet-dot',
                        html: '<span style="display:block;width:16px;height:16px;border-radius:999px;border:3px solid #fff;'
                            + 'box-shadow:0 2px 6px rgba(28,25,20,.35), 0 0 0 4px ' + (moving ? 'rgba(30,158,106,.18)' : 'rgba(154,150,141,.18)') + ';'
                            + 'background:' + (moving ? '#1e9e6a' : '#9a968d') + '"></span>',
                        iconSize: [16, 16],
                        iconAnchor: [8, 8],
                    });

                    const draw = (positions) => {
                        layer.clearLayers();
                        const pts = [];
                        (positions || []).forEach((p) => {
                            if (p == null || p.lat == null || p.lng == null) return;
                            L.marker([p.lat, p.lng], { icon: dot(!!p.moving) }).addTo(layer);
                            pts.push([p.lat, p.lng]);
                        });
                        if (pts.length === 1) {
                            map.setView(pts[0], 12);
                        } else if (pts.length > 1) {
                            map.fitBounds(L.latLngBounds(pts).pad(0.2));
                        }
                    };

                    draw(initial.positions);

                    // Llamada segura: si la telemetría falla o la página ya se cerró, se ignora y se
                    // reintenta en el próximo tick/evento (nunca un Uncaught en consola).
                    const refresh = () => live.call(() => $wire.mapData(), (fresh) => {
                        if (fresh && fresh.positions) draw(fresh.positions);
                    });

                    // Refresco de respaldo (polling cada 30s): garantiza frescura aunque no haya push.
                    live.every(30000, refresh);

                    // Tiempo real (Fase 6): si Laravel Echo está disponible (Soketi), al llegar una posición
                    // nueva por WebSocket se refresca el mapa al instante (debounce para absorber ráfagas).
                    // Si Echo no está (no configurado), queda solo el polling de arriba.
                    if (window.Echo) {
                        let pending = null;
                        let pendingData = null;
                        const liveRefresh = () => {
                            // Mapa al instante (debounce corto para absorber ráfagas de posiciones).
                            if (!live.alive) return;
                            if (!pending) {
                                pending = live.timeout(1500, () => { pending = null; refresh(); });
                            }
                            // KPIs/brief en vivo: refresco del componente Livewire (el mapa va en wire:ignore,
                            // así que no se reinicializa). Debounce más largo para no martillar el servidor.
                            if (!pendingData) {
                                pendingData = live.timeout(4000, () => {
                                    pendingData = null;
                                    live.call(() => $wire.$refresh()); // si falla, queda el polling de 30s
                                });
                            }
                        };
                        try {
                            const channel = window.Echo.channel('fleet');
                            channel.listen('.vehicle.position', liveRefresh);
                            // Al salir se quita ESTE listener (si no, cada visita sumaba uno más al canal).
                            live.onDispose(() => {
                                if (typeof channel.stopListening === 'function') {
                                    channel.stopListening('.vehicle.position', liveRefresh);
                                }
                            });
                        } catch (e) { /* sin tiempo real: queda el polling */ }
                    }

                    // Ajusta el tamaño tras el primer render del layout.
                    live.timeout(250, () => map.invalidateSize());
                }
            </script>
            @endscript
        @endif

    </x-cockpit.surface>
</x-filament-panels::page>
