{{--
    Hub 360 de producto (catálogo commerce) — cockpit de detalle. Agrega ficha (nombre, SKU canónico,
    estado, categoría, vendedor) + variantes/SKUs con inventario on-hand + precios vigentes + órdenes
    recientes que lo contienen. Datos reales y tolerantes vía App\Support\Cockpit\ProductHub ($this->hub),
    cruzados por SKU entre contextos (catalog + inventory + pricing + orders). Capa `.distrack-cockpit`,
    español directo. NUNCA UUID visible (el producto es su nombre + SKU; la orden, el cliente + fecha).
--}}
@php
    $h = $this->hub;
    $p = $h['product'];
    $m = $h['metrics'];

    $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
    $money = static fn (int $cents, string $c): string => $sym($c).' '.number_format($cents / 100, 2);

    $productStatusMap = [
        'published' => ['Publicado', 'positive'],
        'draft' => ['Borrador', 'warning'],
        'archived' => ['Archivado', 'danger'],
    ];
    [$stLabel, $stTone] = $p ? ($productStatusMap[strtolower($p['status'])] ?? [ucfirst(strtolower($p['status'])), '']) : ['—', ''];

    $orderStatusMap = [
        'pending' => ['Pendiente', 'warning'],
        'paid' => ['Pagada', 'agent'],
        'fulfilling' => ['En preparación', 'agent'],
        'cancelled' => ['Anulada', 'danger'],
    ];
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        @if($p)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $p['name'] }}</div>
                        <div class="ck-hub__sub">
                            <span class="ck-mono">SKU {{ $p['canonical_sku'] }}</span>
                            @if($p['category']) · {{ $p['category'] }}@endif
                            @if($p['seller']) · Vendedor: {{ $p['seller'] }}@endif
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $stTone ? 'ck-pill--'.$stTone : '' }}">{{ $stLabel }}</span>
                            @if($p['variants_count'] > 0)<span class="ck-pill">{{ $p['variants_count'] }} {{ $p['variants_count'] === 1 ? 'variante' : 'variantes' }}</span>@endif
                            @if($p['media_count'] > 0)<span class="ck-pill">{{ $p['media_count'] }} {{ $p['media_count'] === 1 ? 'imagen' : 'imágenes' }}</span>@endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    @if($m['stock_available'] > 0)
                        <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>{{ number_format($m['stock_available']) }} en stock</span>
                    @else
                        <span class="ck-pill ck-pill--warning"><span class="ck-pill__dot"></span>Sin stock disponible</span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Stock disponible</div>
                    <div class="ck-stat__value">{{ number_format($m['stock_available']) }}</div>
                    <div class="ck-stat__hint">{{ number_format($m['stock_reserved']) }} reservado</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Variantes</div>
                    <div class="ck-stat__value">{{ $m['variants'] }}</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Órdenes recientes</div>
                    <div class="ck-stat__value">{{ $m['orders'] }}</div>
                    <div class="ck-stat__hint">últimas 12</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Unidades vendidas</div>
                    <div class="ck-stat__value">{{ number_format($m['units_sold']) }}</div>
                    <div class="ck-stat__hint">en esas órdenes</div>
                </div>
            </div>

            {{-- ===== SKUS + INVENTARIO ===== --}}
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">SKUs e inventario · {{ count($h['skus']) }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($h['skus'] as $sk)
                        <div class="ck-event">
                            <span class="ck-event__ic {{ $sk['available'] > 0 ? '' : 'ck-event__ic--red' }}"></span>
                            <span class="ck-event__tx">
                                <span class="ck-mono">{{ $sk['sku'] }}</span> — {{ $sk['name'] }}
                                <small>{{ number_format($sk['available']) }} disponible · {{ number_format($sk['reserved']) }} reservado</small>
                            </span>
                            <span class="ck-pill {{ $sk['is_variant'] ? '' : 'ck-pill--agent' }}">{{ $sk['is_variant'] ? 'Variante' : 'Principal' }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Sin SKUs</div><div class="ck-empty__hint">Este producto no tiene SKU canónico ni variantes.</div></div>
                    @endforelse
                </div>
            </div>

            {{-- ===== PRECIOS VIGENTES ===== --}}
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Precios vigentes · {{ count($h['prices']) }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($h['prices'] as $pr)
                        <div class="ck-event">
                            <span class="ck-event__ic"></span>
                            <span class="ck-event__tx">
                                {{ $pr['list_name'] }}
                                <small><span class="ck-mono">{{ $pr['sku'] }}</span> · Canal {{ $pr['channel'] }} · {{ ucfirst(strtolower($pr['status'])) }}</small>
                            </span>
                            <span class="ck-pill ck-pill--positive">{{ $money($pr['amount_cents'], $pr['currency']) }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Sin precios</div><div class="ck-empty__hint">No hay listas de precios con estos SKUs.</div></div>
                    @endforelse
                </div>
            </div>

            {{-- ===== ÓRDENES RECIENTES ===== --}}
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Órdenes recientes con este producto</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($h['orders'] as $o)
                        @php [$ol, $ot] = $orderStatusMap[strtolower($o['status'])] ?? [ucfirst(strtolower($o['status'])), '']; @endphp
                        <div class="ck-event">
                            <span class="ck-event__ic {{ $ot === 'danger' ? 'ck-event__ic--red' : '' }}"></span>
                            <span class="ck-event__tx">
                                {{ $o['customer'] }}
                                <small>{{ $o['date'] }} · {{ number_format($o['units']) }} u. · {{ $money($o['subtotal_cents'], $o['currency']) }}</small>
                            </span>
                            <span class="ck-pill {{ $ot ? 'ck-pill--'.$ot : '' }}">{{ $ol }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Sin órdenes</div><div class="ck-empty__hint">Ninguna orden reciente contiene este producto.</div></div>
                    @endforelse
                </div>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Producto no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de este producto.</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
