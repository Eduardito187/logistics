{{--
    Hub 360 de una devolución (RMA) — cockpit de detalle (DisTrack OS). Agrega: referencia + tipo + estado
    del ciclo + disposición + almacén, y las líneas (productos devueltos con motivo). Datos reales y
    tolerantes vía App\Support\Cockpit\ReturnHub ($this->hub). Capa `.distrack-cockpit`. Español; NUNCA UUID.
    Conserva el chrome de página Filament (cabecera + acciones de ciclo).
--}}
<x-filament-panels::page>
    @php
        $h = $this->hub;
        $r = $h['ret'];
        $lines = $h['lines'];

        $statusMap = [
            'initiated' => ['Iniciada', 'agent'],
            'received' => ['Recibida', 'warning'],
            'dispositioned' => ['Dispuesta', 'positive'],
            'closed' => ['Cerrada', ''],
        ];
        [$statusLabel, $statusTone] = $r ? ($statusMap[$r['status']] ?? [ucfirst(str_replace('_', ' ', (string) $r['status'])), '']) : ['—', ''];

        $kindMap = [
            'customer_return' => 'Devolución de cliente',
            'return_to_vendor' => 'Devolución a proveedor',
        ];
        $dispositionMap = [
            'restock' => 'Reingreso a stock',
            'return_to_seller' => 'Devolver al vendedor',
            'scrap' => 'Desecho',
        ];

        // Progreso del ciclo (para la tira de estado).
        $steps = ['initiated' => 1, 'received' => 2, 'dispositioned' => 3, 'closed' => 4];
        $current = $r ? ($steps[$r['status']] ?? 0) : 0;
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">
        @if($r)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $r['reference'] ?: 'Devolución' }}</div>
                        <div class="ck-hub__sub">
                            {{ $kindMap[$r['kind']] ?? ucfirst(str_replace('_', ' ', (string) $r['kind'])) }}
                            @if($r['mode']) · {{ ucfirst(strtolower($r['mode'])) }}@endif
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                            @if($r['disposition'])<span class="ck-pill">{{ $dispositionMap[$r['disposition']] ?? ucfirst(str_replace('_', ' ', (string) $r['disposition'])) }}</span>@endif
                            @if($r['warehouse'])<span class="ck-pill">Almacén: {{ $r['warehouse'] }}</span>@endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}"><span class="ck-pill__dot"></span>{{ $statusLabel }}</span>
                    @if($r['created'])<span class="ck-hub__sub">Iniciada: {{ $r['created'] }}</span>@endif
                </div>
            </div>

            {{-- ===== KPI STRIP (ciclo) ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat {{ $current >= 1 ? 'ck-stat--agent' : '' }}">
                    <div class="ck-stat__label">1 · Iniciada</div>
                    <div class="ck-stat__value" style="font-size:1.1rem">{{ $current >= 1 ? '✓' : '—' }}</div>
                </div>
                <div class="ck-stat {{ $current >= 2 ? 'ck-stat--warning' : '' }}">
                    <div class="ck-stat__label">2 · Recibida</div>
                    <div class="ck-stat__value" style="font-size:1.1rem">{{ $current >= 2 ? '✓' : '—' }}</div>
                </div>
                <div class="ck-stat {{ $current >= 3 ? 'ck-stat--positive' : '' }}">
                    <div class="ck-stat__label">3 · Dispuesta</div>
                    <div class="ck-stat__value" style="font-size:1.1rem">{{ $current >= 3 ? '✓' : '—' }}</div>
                </div>
                <div class="ck-stat {{ $current >= 4 ? 'ck-stat--positive' : '' }}">
                    <div class="ck-stat__label">Productos</div>
                    <div class="ck-stat__value">{{ count($lines) }}</div>
                    <div class="ck-stat__hint">{{ $h['units'] }} unidades</div>
                </div>
            </div>

            {{-- ===== TABS ===== --}}
            <div class="ck-tabs">
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'items' }" @click="t = 'items'">Productos<span class="ck-tab__n">{{ count($lines) }}</span></button>
            </div>

            {{-- ===== PANEL · RESUMEN ===== --}}
            <div x-show="t === 'summary'">
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Detalle de la devolución</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>Referencia original</dt><dd>{{ $r['reference'] ?: '—' }}</dd>
                        <dt>Tipo</dt><dd>{{ $kindMap[$r['kind']] ?? ucfirst(str_replace('_', ' ', (string) $r['kind'])) }}</dd>
                        <dt>Estado</dt><dd>{{ $statusLabel }}</dd>
                        <dt>Disposición</dt><dd>{{ $r['disposition'] ? ($dispositionMap[$r['disposition']] ?? ucfirst(str_replace('_', ' ', (string) $r['disposition']))) : '—' }}</dd>
                        <dt>Modo</dt><dd>{{ $r['mode'] ? ucfirst(strtolower($r['mode'])) : '—' }}</dd>
                        <dt>Almacén destino</dt><dd>{{ $r['warehouse'] ?: '—' }}</dd>
                        <dt>Iniciada</dt><dd>{{ $r['created'] ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            {{-- ===== PANEL · PRODUCTOS ===== --}}
            <div x-show="t === 'items'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Productos devueltos</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($lines as $line)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    SKU {{ $line['sku'] }}
                                    @if($line['reason'])<small>{{ $line['reason'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">× {{ $line['quantity'] }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin productos</div><div class="ck-empty__hint">Esta devolución no tiene líneas cargadas.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Devolución no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de esta devolución.</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
