{{--
    Hub 360 de vendedor (seller del marketplace) — cockpit de detalle. Agrega ficha (razón social, NIT,
    estado, contacto) + liquidaciones (pendientes/pagadas, montos) + gestión de sus liquidaciones desde la
    ficha. Datos reales y tolerantes vía App\Support\Cockpit\SellerHub ($this->hub). Capa `.distrack-cockpit`,
    español directo. NUNCA UUID visible.
--}}
@php
    $h = $this->hub;
    $s = $h['seller'];
    $m = $h['metrics'];

    $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
    $money = static fn (int $cents, string $c): string => $sym($c).' '.number_format($cents / 100, 2);

    $sellerStatusMap = [
        'active' => ['Activo', 'positive'],
        'registered' => ['Registrado', 'agent'],
        'suspended' => ['Suspendido', 'danger'],
    ];
    [$stLabel, $stTone] = $s ? ($sellerStatusMap[strtolower($s['status'])] ?? [ucfirst(strtolower($s['status'])), '']) : ['—', ''];

    $settlementStatusMap = [
        'draft' => ['Borrador', ''],
        'confirmed' => ['Confirmada', 'agent'],
        'paid' => ['Pagada', 'positive'],
        'cancelled' => ['Anulada', 'danger'],
    ];
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit" x-data="{ t: 'summary' }">
        @if($s)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $s['name'] }}</div>
                        <div class="ck-hub__sub">
                            @if($s['tax_id'])<span class="ck-mono">NIT {{ $s['tax_id'] }}</span> · @endif
                            {{ $m['settlements'] }} {{ $m['settlements'] === 1 ? 'liquidación' : 'liquidaciones' }}
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $stTone ? 'ck-pill--'.$stTone : '' }}">{{ $stLabel }}</span>
                            @if($s['contact_name'])<span class="ck-pill">{{ $s['contact_name'] }}</span>@endif
                            @if($s['contact_phone'])<span class="ck-pill ck-mono">{{ $s['contact_phone'] }}</span>@endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    @if($m['pending'] > 0)
                        <span class="ck-pill ck-pill--warning"><span class="ck-pill__dot"></span>{{ $m['pending'] }} liquidaciones pendientes</span>
                    @else
                        <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>Sin pendientes</span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat {{ $m['pending'] > 0 ? 'ck-stat--warning' : '' }}">
                    <div class="ck-stat__label">Pendientes</div>
                    <div class="ck-stat__value">{{ $m['pending'] }}</div>
                    <div class="ck-stat__hint">{{ $m['paid'] }} pagadas</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Bruto histórico</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($m['gross_cents'], $m['currency']) }}</div>
                </div>
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Neto histórico</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($m['net_cents'], $m['currency']) }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Comisión</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($m['commission_cents'], $m['currency']) }}</div>
                </div>
            </div>

            {{-- ===== GESTIÓN DEL VENDEDOR ===== --}}
            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del vendedor</div>
                    <div class="ck-hub__pills" style="margin-top:.7rem">
                        @foreach($this->managementLinks() as $link)
                            <a href="{{ $link['url'] }}" wire:navigate class="ck-pill ck-pill--agent">{{ $link['label'] }} →</a>
                        @endforeach
                    </div>
                    <div class="ck-hub__sub" style="margin-top:.7rem">Las liquidaciones dependen del vendedor: se gestionan desde su ficha, no desde el menú.</div>
                </div>
            @endif

            {{-- ===== LIQUIDACIONES RECIENTES ===== --}}
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Liquidaciones recientes</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($h['recent'] as $r)
                        @php [$rl, $rt] = $settlementStatusMap[strtolower($r['status'])] ?? [ucfirst(strtolower($r['status'])), '']; @endphp
                        <div class="ck-event">
                            <span class="ck-event__ic {{ $rt === 'danger' ? 'ck-event__ic--red' : '' }}"></span>
                            <span class="ck-event__tx">
                                {{ $r['period'] }}
                                <small>Bruto {{ $money($r['gross_cents'], $r['currency']) }} · Neto {{ $money($r['net_cents'], $r['currency']) }}</small>
                            </span>
                            <span class="ck-pill {{ $rt ? 'ck-pill--'.$rt : '' }}">{{ $rl }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Sin liquidaciones</div><div class="ck-empty__hint">Este vendedor todavía no tiene liquidaciones.</div></div>
                    @endforelse
                </div>
            </div>

            {{-- ===== CONTACTO ===== --}}
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Contacto</div>
                <dl class="ck-dl" style="margin-top:.9rem">
                    <dt>Razón social</dt><dd>{{ $s['name'] }}</dd>
                    <dt>NIT</dt><dd class="ck-mono">{{ $s['tax_id'] ?: '—' }}</dd>
                    <dt>Contacto</dt><dd>{{ $s['contact_name'] ?: '—' }}</dd>
                    <dt>Email</dt><dd>{{ $s['contact_email'] ?: '—' }}</dd>
                    <dt>Teléfono</dt><dd class="ck-mono">{{ $s['contact_phone'] ?: '—' }}</dd>
                </dl>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Vendedor no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de este vendedor.</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
