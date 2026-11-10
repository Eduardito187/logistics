{{--
    Hub 360 de envío/pedido — cockpit de detalle (DisTrack OS, Fase 2). Agrega: nº de pedido + cliente +
    estado, seguimiento en vivo (ETA/posición), ventana comprometida, dirección con confianza, entrega
    (vehículo + prueba de entrega) y productos/bultos. Datos reales y tolerantes vía
    App\Support\Cockpit\ShipmentHub ($this->hub). Capa `.distrack-cockpit`. Español; NUNCA UUID visible.
    Conserva el chrome de página Filament (cabecera + acción Editar).
--}}
<x-filament-panels::page>
    @php
        $h = $this->hub;
        $s = $h['shipment'];
        $tr = $h['tracking'];
        $del = $h['delivery'];
        $units = $h['units'];

        $statusMap = [
            'RECEIVED' => ['Recibido', ''],
            'PLANNED' => ['Planificado', 'agent'],
            'IN_EXECUTION' => ['En ejecución', 'warning'],
            'DELIVERED' => ['Entregado', 'positive'],
            'FAILED' => ['Fallido', 'danger'],
            'CANCELLED' => ['Cancelado', ''],
        ];
        $status = $s['status'] ?? 'RECEIVED';
        [$statusLabel, $statusTone] = $statusMap[$status] ?? [ucfirst(strtolower(str_replace('_', ' ', (string) $status))), ''];

        $confMap = [
            'HIGH' => ['Alta', 'positive'],
            'MEDIUM' => ['Media', 'warning'],
            'LOW' => ['Baja', 'danger'],
        ];
        [$confLabel, $confTone] = $confMap[$s['confidence_level'] ?? ''] ?? ['—', ''];

        $deliveryStatusMap = [
            'DELIVERED' => ['Entregado', 'positive'],
            'FAILED' => ['Fallido', 'danger'],
            'IN_TRANSIT' => ['En tránsito', 'warning'],
            'LOADED' => ['Cargado', 'agent'],
            'PREPARED' => ['Preparado', ''],
            'PENDING' => ['Pendiente', ''],
        ];

        $window = '—';
        if ($s && $s['window_start'] && $s['window_end']) {
            $date = $s['delivery_date'] ? \Illuminate\Support\Carbon::parse($s['delivery_date'])->isoFormat('DD MMM') : '';
            $window = trim($date.' '.$s['window_start'].'–'.$s['window_end']);
        }

        $unitStatus = static fn (string $st): string => match (strtoupper($st)) {
            'DELIVERED' => 'Entregado',
            'IN_TRANSIT' => 'En tránsito',
            'FAILED' => 'Fallido',
            'RECEIVED' => 'Recibido',
            'LOADED' => 'Cargado',
            default => ucfirst(strtolower($st)),
        };
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        @if($s)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $s['external_id'] ?: 'Pedido' }}</div>
                        <div class="ck-hub__sub">
                            {{ $s['customer_name'] }}{{ $s['customer_phone'] ? ' · '.$s['customer_phone'] : '' }}
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                            <span class="ck-pill">Origen: {{ $s['source'] }}</span>
                            @if($del && $del['fulfillment_mode'])
                                <span class="ck-pill">{{ ucfirst(strtolower($del['fulfillment_mode'])) }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="ck-hub__aside">
                    @if($tr)
                        <span class="ck-pill {{ $tr['position_is_live'] ? 'ck-pill--agent' : '' }}">
                            <span class="ck-pill__dot"></span>{{ $tr['eta'] ? 'ETA '.$tr['eta'] : 'En seguimiento' }}
                        </span>
                        <span class="ck-hub__sub">{{ $tr['remaining_stops'].' paradas restantes'.($tr['route_position'] !== null ? ' · parada #'.$tr['route_position'] : '') }}</span>
                    @elseif($status === 'DELIVERED')
                        <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>Entregado</span>
                    @else
                        <span class="ck-pill">Sin seguimiento activo</span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat {{ $statusTone ? 'ck-stat--'.$statusTone : '' }}">
                    <div class="ck-stat__label">Estado</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $statusLabel }}</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">ETA</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $tr && $tr['eta'] ? $tr['eta'] : '—' }}</div>
                    <div class="ck-stat__hint">{{ $tr ? $tr['remaining_stops'].' paradas restantes' : 'Sin ruta' }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Ventana comprometida</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $window }}</div>
                </div>
                <div class="ck-stat {{ $confTone ? 'ck-stat--'.$confTone : '' }}">
                    <div class="ck-stat__label">Confianza de dirección</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $confLabel }}</div>
                    <div class="ck-stat__hint">{{ $s['confidence_score'] }} / 100</div>
                </div>
            </div>

            {{-- ===== TABS ===== --}}
            <div class="ck-tabs">
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'products' }" @click="t = 'products'">Productos<span class="ck-tab__n">{{ count($s['lines']) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'delivery' }" @click="t = 'delivery'">Entrega</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'activity' }" @click="t = 'activity'">Actividad</button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'notes' }" @click="t = 'notes'">Notas<span class="ck-tab__n">{{ count($this->notes) }}</span></button>
            </div>

            {{-- ===== PANEL · RESUMEN ===== --}}
            <div x-show="t === 'summary'">

            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del envío</div>
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
                        <div class="ck-card__eyebrow">Dirección de entrega</div>
                        <div class="ck-card__title" style="font-size:1.1rem">{{ $s['address_text'] }}</div>
                        <dl class="ck-dl" style="margin-top:1rem">
                            <dt>Referencia</dt><dd>{{ $s['address_reference'] ?: '—' }}</dd>
                            <dt>Nodo destino</dt><dd>{{ $s['node_name'] ?: '—' }}</dd>
                            <dt>Confianza</dt><dd>{{ $confLabel }} · {{ $s['confidence_score'] }}/100</dd>
                            <dt>Documento cliente</dt><dd class="ck-mono">{{ $s['customer_document'] ?: '—' }}</dd>
                            <dt>Email</dt><dd>{{ $s['customer_email'] ?: '—' }}</dd>
                        </dl>
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Entrega y seguimiento</div>
                        <dl class="ck-dl" style="margin-top:.9rem">
                            <dt>Vehículo asignado</dt><dd>@if($del && !empty($del['vehicle_id']))<a href="{{ \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $del['vehicle_id']]) }}" wire:navigate class="ck-link">{{ $del['vehicle_plate'] }}</a>@else{{ $del && $del['vehicle_plate'] ? $del['vehicle_plate'] : '—' }}@endif</dd>
                            <dt>Estado de entrega</dt>
                            <dd>
                                @php [$dsl, $dst] = $del && $del['status'] ? ($deliveryStatusMap[strtoupper($del['status'])] ?? [ucfirst(strtolower($del['status'])), '']) : ['—', '']; @endphp
                                {{ $dsl }}
                            </dd>
                            <dt>ETA</dt><dd>{{ $tr && $tr['eta'] ? $tr['eta'] : '—' }}</dd>
                            <dt>Paradas restantes</dt><dd>{{ $tr ? $tr['remaining_stops'] : '—' }}</dd>
                            <dt>Bultos</dt><dd>{{ count($units) ?: '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>

            {{-- ===== PANEL · PRODUCTOS ===== --}}
            <div x-show="t === 'products'" x-cloak>
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Productos del pedido</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($s['lines'] as $line)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ $line['product_name'] }}
                                    <small>SKU {{ $line['sku'] }}</small>
                                </span>
                                <span class="ck-event__when">× {{ $line['quantity'] }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin líneas</div><div class="ck-empty__hint">Este pedido no tiene productos cargados.</div></div>
                        @endforelse
                    </div>
                </div>

                @if(count($units))
                    <div class="ck-card ck-section">
                        <div class="ck-card__eyebrow">Bultos físicos</div>
                        <div class="ck-events" style="margin-top:.6rem">
                            @foreach($units as $unit)
                                <div class="ck-event">
                                    <span class="ck-event__ic"></span>
                                    <span class="ck-event__tx">
                                        {{ $unit['product_name'] }}{{ $unit['piece'] ? ' · pieza '.$unit['piece'] : '' }}
                                        <small>SKU {{ $unit['sku'] }}</small>
                                    </span>
                                    <span class="ck-event__when">{{ $unitStatus($unit['status']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- ===== PANEL · ENTREGA ===== --}}
            <div x-show="t === 'delivery'" x-cloak>
                <div class="ck-grid ck-grid--2 ck-section">
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Prueba de entrega</div>
                        @if($del)
                            @if($del['pod_photo_url'])
                                <img src="{{ $del['pod_photo_url'] }}" alt="Prueba de entrega"
                                     style="width:100%;max-width:360px;border-radius:12px;border:1px solid var(--ck-line);margin-top:.6rem"
                                     onerror="this.style.display='none'">
                            @endif
                            <dl class="ck-dl" style="margin-top:1rem">
                                <dt>Confirmación</dt><dd class="ck-mono">{{ $del['pod_confirmation'] ?: '—' }}</dd>
                                <dt>Capturada</dt><dd>{{ $del['pod_captured_at'] ?: '—' }}</dd>
                                @if($del['failure_reason'])
                                    <dt>Motivo de fallo</dt><dd>{{ $del['failure_reason'] }}</dd>
                                @endif
                            </dl>
                        @else
                            <div class="ck-empty"><div class="ck-empty__title">Sin entrega aún</div><div class="ck-empty__hint">Este pedido todavía no tiene una entrega en ejecución.</div></div>
                        @endif
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">Detalle de ejecución</div>
                        <dl class="ck-dl" style="margin-top:.9rem">
                            <dt>Vehículo</dt><dd>@if($del && !empty($del['vehicle_id']))<a href="{{ \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $del['vehicle_id']]) }}" wire:navigate class="ck-link">{{ $del['vehicle_plate'] }}</a>@else{{ $del && $del['vehicle_plate'] ? $del['vehicle_plate'] : '—' }}@endif</dd>
                            <dt>Estado</dt>
                            <dd>
                                @php [$dsl2, $dst2] = $del && $del['status'] ? ($deliveryStatusMap[strtoupper($del['status'])] ?? [ucfirst(strtolower($del['status'])), '']) : ['—', '']; @endphp
                                {{ $dsl2 }}
                            </dd>
                            <dt>Modo</dt><dd>{{ $del && $del['fulfillment_mode'] ? ucfirst(strtolower($del['fulfillment_mode'])) : '—' }}</dd>
                        </dl>
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
