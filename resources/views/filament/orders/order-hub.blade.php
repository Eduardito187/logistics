{{--
    Hub 360 de pedido/cliente — cockpit de detalle (DisTrack OS, Fase 2). Agrega: cliente + estado + total,
    dirección de entrega, estado de pago y factura, y productos del pedido. Datos reales y tolerantes vía
    App\Support\Cockpit\OrderHub ($this->hub). Capa `.distrack-cockpit`. Español; NUNCA UUID visible (la
    referencia es el cliente y los datos del pedido). Conserva el chrome de página Filament (cabecera +
    acción Cancelar).
--}}
<x-filament-panels::page>
    @php
        $h = $this->hub;
        $o = $h['order'];
        $pay = $h['payment'];
        $inv = $h['invoice'];
        $ships = $h['shipments'];

        // Estado del envío → etiqueta legible + tono (para la lista de envíos del pedido).
        $shipStatusMap = [
            'RECEIVED' => ['Recibido', ''],
            'PLANNED' => ['Planificado', 'agent'],
            'IN_EXECUTION' => ['En ejecución', 'warning'],
            'DELIVERED' => ['Entregado', 'positive'],
            'FAILED' => ['Fallido', 'danger'],
            'CANCELLED' => ['Cancelado', ''],
        ];

        $currency = $o['currency'] ?? 'BOB';
        $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
        $money = static function (?int $cents, string $c = 'BOB') use ($sym): string {
            return $cents === null ? '—' : $sym($c).' '.number_format($cents / 100, 2);
        };

        $statusMap = [
            'pending' => ['Pendiente', ''],
            'paid' => ['Pagado', 'positive'],
            'fulfilling' => ['En preparación', 'agent'],
            'cancelled' => ['Cancelado', 'danger'],
        ];
        [$statusLabel, $statusTone] = $statusMap[$o['status'] ?? ''] ?? [ucfirst(str_replace('_', ' ', (string) ($o['status'] ?? '—'))), ''];

        $payMap = [
            'pending' => ['Pendiente', ''],
            'authorized' => ['Autorizado', 'agent'],
            'captured' => ['Capturado', 'positive'],
            'failed' => ['Fallido', 'danger'],
            'refunded' => ['Reembolsado', 'warning'],
        ];
        [$payLabel, $payTone] = $pay ? ($payMap[strtolower($pay['status'])] ?? [ucfirst(strtolower($pay['status'])), '']) : ['Sin pago', ''];

        $invMap = [
            'issued' => ['Emitida', 'positive'],
            'draft' => ['Borrador', ''],
            'void' => ['Anulada', 'danger'],
            'cancelled' => ['Anulada', 'danger'],
        ];
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        @if($o)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $o['customer_name'] }}</div>
                        <div class="ck-hub__sub">
                            Pedido · {{ $o['items'] }} {{ $o['items'] === 1 ? 'ítem' : 'ítems' }} · {{ $money($o['total_cents'], $currency) }}
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                            <span class="ck-pill {{ $payTone ? 'ck-pill--'.$payTone : '' }}">Pago: {{ $payLabel }}</span>
                            @if($o['shipment_requested'])
                                <span class="ck-pill ck-pill--agent">Envío solicitado</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    <span class="ck-pill">{{ $money($o['total_cents'], $currency) }}</span>
                    @if($o['phone'])
                        <span class="ck-hub__sub ck-mono">{{ $o['phone'] }}</span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat {{ $statusTone ? 'ck-stat--'.$statusTone : '' }}">
                    <div class="ck-stat__label">Estado del pedido</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $statusLabel }}</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Total</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($o['total_cents'], $currency) }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Ítems</div>
                    <div class="ck-stat__value">{{ $o['items'] }}</div>
                    <div class="ck-stat__hint">{{ count($o['lines']) }} {{ count($o['lines']) === 1 ? 'línea' : 'líneas' }}</div>
                </div>
                <div class="ck-stat {{ $payTone ? 'ck-stat--'.$payTone : '' }}">
                    <div class="ck-stat__label">Pago</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $payLabel }}</div>
                    <div class="ck-stat__hint">{{ $pay ? ucfirst(strtolower($pay['method'])) : 'No iniciado' }}</div>
                </div>
            </div>

            {{-- ===== TABS ===== --}}
            <div class="ck-tabs">
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'products' }" @click="t = 'products'">Productos<span class="ck-tab__n">{{ count($o['lines']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'payment' }" @click="t = 'payment'">Pago y factura</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'shipments' }" @click="t = 'shipments'">Envíos<span class="ck-tab__n">{{ count($ships) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'activity' }" @click="t = 'activity'">Actividad</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'notes' }" @click="t = 'notes'">Notas<span class="ck-tab__n">{{ count($this->notes) }}</span></button>
            </div>

            {{-- ===== PANEL · RESUMEN ===== --}}
            <div x-show="t === 'summary'">

            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del pedido</div>
                    <div class="ck-hub__pills" style="margin-top:.7rem">
                        @foreach($this->managementLinks() as $m)
                            <a href="{{ $m['url'] }}" wire:navigate class="ck-pill ck-pill--agent">{{ $m['label'] }} →</a>
                        @endforeach
                    </div>
                    <div class="ck-hub__sub" style="margin-top:.7rem">Estos registros dependen de esta entidad: se gestionan desde su ficha, no desde el menú.</div>
                </div>
            @endif
                <div class="ck-grid ck-grid--2 ck-section">
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Cliente y entrega</div>
                        <div class="ck-card__title" style="font-size:1.1rem">{{ $o['customer_name'] }}</div>
                        <dl class="ck-dl" style="margin-top:1rem">
                            <dt>Teléfono</dt><dd class="ck-mono">{{ $o['phone'] ?: '—' }}</dd>
                            <dt>Email</dt><dd>{{ $o['email'] ?: '—' }}</dd>
                            <dt>Dirección</dt><dd>{{ $o['address_text'] ?: '—' }}</dd>
                            <dt>Referencia</dt><dd>{{ $o['address_reference'] ?: '—' }}</dd>
                            <dt>Envío</dt><dd>{{ $o['shipment_requested'] ? 'Solicitado' : 'No solicitado' }}</dd>
                        </dl>
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Pago y factura</div>
                        <dl class="ck-dl" style="margin-top:.9rem">
                            <dt>Estado de pago</dt><dd>{{ $payLabel }}</dd>
                            <dt>Método</dt><dd>{{ $pay ? ucfirst(strtolower($pay['method'])) : '—' }}</dd>
                            <dt>Monto</dt><dd>{{ $pay ? $money($pay['amount_cents'], $pay['currency'] ?? $currency) : '—' }}</dd>
                            <dt>Factura</dt>
                            <dd>
                                @php [$il, $it2] = $inv ? ($invMap[strtolower($inv['status'])] ?? [ucfirst(strtolower($inv['status'])), '']) : ['—', '']; @endphp
                                {{ $inv && $inv['number'] ? $inv['number'] : $il }}
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · PRODUCTOS ===== --}}
            <div x-show="t === 'products'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Productos del pedido</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($o['lines'] as $line)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ $line['product_name'] }}
                                    <small>SKU {{ $line['sku'] }} · {{ $money($line['unit_price_cents'], $currency) }} c/u</small>
                                </span>
                                <span class="ck-event__when">× {{ $line['quantity'] }}</span>
                                <span class="ck-pill">{{ $money($line['unit_price_cents'] * $line['quantity'], $currency) }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin productos</div><div class="ck-empty__hint">Este pedido no tiene líneas cargadas.</div></div>
                        @endforelse
                    </div>
                    <div class="ck-bar ck-bar--green" style="margin-top:1rem">
                        <span class="ck-bar__label"><b style="color:var(--ck-ink)">Total</b></span>
                        <span class="ck-bar__count">{{ $money($o['total_cents'], $currency) }}</span>
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · PAGO Y FACTURA ===== --}}
            <div x-show="t === 'payment'" x-cloak>
                <div class="ck-grid ck-grid--2 ck-section">
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Pago</div>
                        @if($pay)
                            <div style="margin-top:.4rem"><span class="ck-pill {{ $payTone ? 'ck-pill--'.$payTone : '' }}">{{ $payLabel }}</span></div>
                            <dl class="ck-dl" style="margin-top:1rem">
                                <dt>Método</dt><dd>{{ ucfirst(strtolower($pay['method'])) }}</dd>
                                <dt>Monto</dt><dd>{{ $money($pay['amount_cents'], $pay['currency'] ?? $currency) }}</dd>
                                <dt>Referencia pasarela</dt><dd class="ck-mono">{{ $pay['gateway_ref'] ?: '—' }}</dd>
                            </dl>
                        @else
                            <div class="ck-empty"><div class="ck-empty__title">Pago no iniciado</div><div class="ck-empty__hint">Todavía no se registró un intento de pago para este pedido.</div></div>
                        @endif
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Factura</div>
                        @if($inv)
                            @php [$il2, $it3] = $invMap[strtolower($inv['status'])] ?? [ucfirst(strtolower($inv['status'])), '']; @endphp
                            <div style="margin-top:.4rem"><span class="ck-pill {{ $it3 ? 'ck-pill--'.$it3 : '' }}">{{ $il2 }}</span></div>
                            <dl class="ck-dl" style="margin-top:1rem">
                                <dt>Número</dt><dd class="ck-mono">{{ $inv['number'] ?: '—' }}</dd>
                                <dt>Total facturado</dt><dd>{{ $money($inv['total_cents'], $currency) }}</dd>
                                <dt>Emitida</dt><dd>{{ $inv['issued_at'] ? \Illuminate\Support\Carbon::parse($inv['issued_at'])->isoFormat('DD MMM YYYY') : '—' }}</dd>
                            </dl>
                        @else
                            <div class="ck-empty"><div class="ck-empty__title">Sin factura</div><div class="ck-empty__hint">Aún no se emitió una factura para este pedido.</div></div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · ENVÍOS (cross-link al hub de cada envío) ===== --}}
            <div x-show="t === 'shipments'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Envíos de este pedido</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($ships as $sh)
                            @php [$shLbl, $shTone] = $shipStatusMap[strtoupper($sh['status'])] ?? [ucfirst(strtolower(str_replace('_', ' ', $sh['status']))), '']; @endphp
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    <a href="{{ \Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource::getUrl('view', ['record' => $sh['id']]) }}" wire:navigate class="ck-link">{{ $sh['reference'] }}</a>
                                </span>
                                <span class="ck-pill {{ $shTone ? 'ck-pill--'.$shTone : '' }}">{{ $shLbl }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin envíos</div><div class="ck-empty__hint">Este pedido todavía no generó un envío (se crea al pagarse).</div></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · ACTIVIDAD (línea de tiempo) ===== --}}
            <div x-show="t === 'activity'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Línea de tiempo</div>
                    <div style="margin-top:.6rem"><x-cockpit.timeline :events="$this->timeline" /></div>
                </div>
            </div>
            {{-- ===== PANEL · NOTAS Y SEGUIMIENTOS ===== --}}
            <div x-show="t === 'notes'" x-cloak>
                <x-cockpit.notes :notes="$this->notes" />
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Pedido no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de este pedido.</div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
