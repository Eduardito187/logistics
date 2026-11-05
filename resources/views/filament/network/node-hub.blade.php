{{--
    Hub 360 de nodo de red — cockpit de detalle (DisTrack OS, Fase 2). Agrega: ficha (código/tipo/
    departamento/estado/ubicación en mapa), flota con base en el nodo, envíos destinados, muelles de
    recepción y conexiones con otros nodos. Datos reales y tolerantes vía App\Support\Cockpit\NodeHub
    ($this->hub). Capa `.distrack-cockpit`. Mapa Leaflet AUTO-HOSPEDADO (/vendor/leaflet, cero CDN).
    Español; NUNCA UUID visible. Conserva el chrome de página Filament.
--}}
<x-filament-panels::page>
    @php
        $h = $this->hub;
        $n = $h['node'];
        $fleet = $h['fleet'];
        $ship = $h['shipments'];
        $docks = $h['docks'];
        $conns = $h['connections'];

        $typeMap = [
            'distribution_center' => 'Centro de distribución',
            'warehouse' => 'Almacén',
            'hub' => 'Hub',
            'cross_dock' => 'Cross-dock',
            'store' => 'Tienda',
            'pickup_point' => 'Punto de retiro',
        ];
        $typeLabel = $n ? ($typeMap[$n['type']] ?? ucfirst(str_replace('_', ' ', (string) $n['type']))) : '—';
        $statusLabel = $n && strtolower((string) $n['status']) === 'active' ? 'Activo' : 'Inactivo';
        $statusTone = $n && strtolower((string) $n['status']) === 'active' ? 'positive' : '';

        $vehStatus = static fn (string $st): string => match (strtolower($st)) {
            'available' => 'Disponible',
            'in_service' => 'En servicio',
            'maintenance' => 'En taller',
            default => ucfirst(strtolower($st)),
        };
        $corridor = static fn (string $c): string => strtoupper(str_replace(['cd', '_'], ['CD', ' '], $c));
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        @if($n)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $n['name'] }}</div>
                        <div class="ck-hub__sub">
                            <span class="ck-mono">{{ $n['code'] }}</span> · {{ $typeLabel }}{{ $n['department'] ? ' · '.ucfirst((string) $n['department']) : '' }}
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                            <span class="ck-pill ck-mono">{{ number_format($n['lat'], 4) }}, {{ number_format($n['lng'], 4) }}</span>
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ $fleet['total'] }} vehículos en base</span>
                    <span class="ck-hub__sub">{{ $ship['active'] }} envíos activos destinados</span>
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Vehículos en base</div>
                    <div class="ck-stat__value">{{ $fleet['total'] }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Envíos destinados</div>
                    <div class="ck-stat__value">{{ number_format($ship['total']) }}</div>
                    <div class="ck-stat__hint">{{ $ship['active'] }} activos</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Muelles de recepción</div>
                    <div class="ck-stat__value">{{ count($docks) ?: '—' }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Conexiones</div>
                    <div class="ck-stat__value">{{ count($conns) ?: '—' }}</div>
                </div>
            </div>

            {{-- ===== TABS ===== --}}
            <div class="ck-tabs">
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'fleet' }" @click="t = 'fleet'">Flota<span class="ck-tab__n">{{ $fleet['total'] }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'links' }" @click="t = 'links'">Conexiones<span class="ck-tab__n">{{ count($conns) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'docks' }" @click="t = 'docks'">Muelles<span class="ck-tab__n">{{ count($docks) }}</span></button>
            </div>

            {{-- ===== PANEL · RESUMEN (mapa) ===== --}}
            <div x-show="t === 'summary'">
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Ubicación</div>
                    <div id="node-hub-map" style="height:380px;width:100%;border-radius:12px;overflow:hidden;margin-top:.6rem;z-index:0"></div>
                </div>
            </div>

            {{-- ===== PANEL · FLOTA ===== --}}
            <div x-show="t === 'fleet'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Flota con base en este nodo</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($fleet['vehicles'] as $veh)
                            <div class="ck-event">
                                <span class="ck-event__ic {{ strtolower($veh['status']) === 'maintenance' ? 'ck-event__ic--amber' : '' }}"></span>
                                <span class="ck-event__tx">
                                    {{ $veh['plate'] }}
                                    @if($veh['label'])<small>{{ $veh['label'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">{{ $vehStatus($veh['status']) }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin flota en base</div><div class="ck-empty__hint">No hay vehículos con base en este nodo.</div></div>
                        @endforelse
                    </div>
                    @if($fleet['total'] > count($fleet['vehicles']))
                        <div class="ck-hub__sub" style="margin-top:.8rem">Mostrando {{ count($fleet['vehicles']) }} de {{ $fleet['total'] }}.</div>
                    @endif
                </div>
            </div>

            {{-- ===== PANEL · CONEXIONES ===== --}}
            <div x-show="t === 'links'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Conexiones con otros nodos</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($conns as $c)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ $c['other'] }}
                                    <small>{{ $corridor($c['corridor_type']) }}</small>
                                </span>
                                <span class="ck-pill {{ $c['direction'] === 'out' ? 'ck-pill--agent' : '' }}">{{ $c['direction'] === 'out' ? 'Saliente →' : '← Entrante' }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin conexiones</div><div class="ck-empty__hint">Este nodo no tiene tramos definidos con otros nodos.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · MUELLES ===== --}}
            <div x-show="t === 'docks'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Muelles de recepción</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($docks as $dock)
                            <div class="ck-event">
                                <span class="ck-event__ic {{ $dock['active'] ? '' : 'ck-event__ic--red' }}"></span>
                                <span class="ck-event__tx">
                                    {{ $dock['name'] }}
                                    <small class="ck-mono">{{ $dock['code'] }}</small>
                                </span>
                                <span class="ck-event__when">{{ $dock['slots'] }} citas/slot</span>
                                <span class="ck-pill {{ $dock['active'] ? 'ck-pill--positive' : 'ck-pill--danger' }}">{{ $dock['active'] ? 'Activo' : 'Inactivo' }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin muelles</div><div class="ck-empty__hint">Este nodo no tiene muelles de recepción (no es almacén o no se configuraron).</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Nodo no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de este nodo.</div>
            </div>
        @endif

    </div>

    @assets
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
    @endassets

    @if($n)
        @script
        <script>
            const lat = @js($n['lat']);
            const lng = @js($n['lng']);
            const label = @js($n['name'].' · '.$n['code']);
            const el = document.getElementById('node-hub-map');
            if (el && lat !== null && lng !== null && window.L) {
                const map = L.map(el).setView([lat, lng], 14);
                window.DismacMaps.tileLayer(L, {
                    url: @js(config('maps.tile_url')),
                    fallbackUrl: @js(config('maps.tile_fallback_url')),
                    attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                    maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
                }).addTo(map);
                L.marker([lat, lng]).addTo(map).bindPopup(label).openPopup();
                setTimeout(() => map.invalidateSize(), 250);
            }
        </script>
        @endscript
    @endif
</x-filament-panels::page>
