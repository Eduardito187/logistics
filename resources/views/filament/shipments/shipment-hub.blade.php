{{--
    Hub 360 de envío/pedido — cockpit de detalle (DisTrack OS, Fase 2). Agrega: nº de pedido + cliente +
    estado, seguimiento en vivo (ETA/posición), ventana comprometida, dirección con confianza, entrega
    (vehículo + prueba de entrega) y productos. Datos reales y tolerantes vía
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

        // Texto de avance en la ruta: nunca «0 paradas» para un pedido entregado o sin ruta.
        $isDelivered = $status === 'DELIVERED' || ($tr && strtoupper((string) $tr['status']) === 'DELIVERED');
        $stopsText = static function (?array $tr): string {
            if (!$tr || $tr['route_position'] === null) {
                return 'Sin ruta asignada';
            }
            $n = (int) $tr['remaining_stops'];
            $head = $n === 0 ? 'Es la próxima parada' : ($n === 1 ? 'Falta 1 parada' : 'Faltan '.$n.' paradas');

            return $head.' · parada #'.$tr['route_position'];
        };
        $evidenceLabel = static fn (string $kind): string => match ($kind) {
            'signature' => 'Firma del cliente',
            'id_document' => 'Documento del cliente',
            'damage' => 'Daño reportado',
            default => 'Foto de la entrega',
        };

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
                    @if($isDelivered)
                        <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>Entregado</span>
                        <span class="ck-hub__sub">Pedido cerrado: ya no se puede editar</span>
                    @elseif($tr)
                        <span class="ck-pill {{ $tr['position_is_live'] ? 'ck-pill--agent' : '' }}">
                            <span class="ck-pill__dot"></span>{{ $tr['eta'] ? 'ETA '.$tr['eta'] : 'En seguimiento' }}
                        </span>
                        <span class="ck-hub__sub">{{ $stopsText($tr) }}</span>
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
                    <div class="ck-stat__hint">{{ $isDelivered ? 'Entregado' : $stopsText($tr) }}</div>
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
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'tracking' }" @click="t = 'tracking'">{{ __('Tracking') }}<span class="ck-tab__n">{{ count($this->labels) }}</span></button>
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
                            <dt>Paradas restantes</dt><dd>{{ $isDelivered ? 'Entregado' : $stopsText($tr) }}</dd>
                            <dt>Productos</dt><dd>{{ count($units) ?: '—' }}</dd>
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
                                <span class="ck-event__when">
                                    × {{ $line['quantity'] }}
                                    @if(($line['returned'] ?? 0) > 0)
                                        <span class="ck-pill ck-pill--warning" style="margin-left:.4rem">{{ $line['returned'] >= $line['quantity'] ? 'Devuelto' : 'Devueltos '.$line['returned'].' de '.$line['quantity'] }}</span>
                                    @endif
                                </span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin líneas</div><div class="ck-empty__hint">Este pedido no tiene productos cargados.</div></div>
                        @endforelse
                    </div>
                </div>

                @if(count($units))
                    <div class="ck-card ck-section">
                        <div class="ck-card__eyebrow">Productos físicos</div>
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
                            @if(count($del['evidence']))
                                {{-- Firma del cliente, foto(s) de la entrega y documento: como máximo 3 por fila. --}}
                                <div style="display:grid;gap:.8rem;margin-top:.8rem;grid-template-columns:repeat(auto-fill,minmax(max(150px,calc((100% - 1.6rem) / 3)),1fr))">
                                    @foreach($del['evidence'] as $ev)
                                        <a href="{{ $ev['url'] }}" target="_blank" rel="noopener" style="display:block;text-decoration:none">
                                            <img src="{{ $ev['url'] }}" alt="{{ $evidenceLabel($ev['kind']) }}" loading="lazy"
                                                 style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:12px;border:1px solid var(--ck-line);background:#fff"
                                                 onerror="this.style.display='none'">
                                            <span class="ck-hub__sub" style="display:block;margin-top:.35rem">{{ $evidenceLabel($ev['kind']) }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @elseif($del['pod_photo_url'])
                                <img src="{{ $del['pod_photo_url'] }}" alt="Prueba de entrega"
                                     style="width:100%;max-width:360px;border-radius:12px;border:1px solid var(--ck-line);margin-top:.6rem"
                                     onerror="this.style.display='none'">
                            @elseif(strtoupper((string) $del['status']) === 'DELIVERED')
                                <div class="ck-hub__sub" style="margin-top:.6rem">Esta entrega no tiene firma ni fotos guardadas.</div>
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

            {{-- ===== PANEL · SEGUIMIENTO ===== --}}
            {{--
                Tres bloques: (1) estado actual + hitos del ciclo de vida (asignado→preparado→cargado→
                entregado) + línea de tiempo de transiciones; (2) etiquetas emitidas con preview visual
                (QR + Code128 + datos legibles), reutilizando LabelPreviewRenderer; (3) estado de entrega,
                ETA y vehículo (placa legible, NUNCA UUID). Cada bloque es tolerante: degrada a vacío sin romper.
            --}}
            <div x-show="t === 'tracking'" x-cloak>
                @php
                    $pg = $this->progress;

                    // Formato corto y tolerante de una marca ISO 8601 a "19 oct 09:00".
                    $shortDt = static function (?string $iso): string {
                        if ($iso === null || $iso === '') {
                            return '—';
                        }
                        try {
                            return \Illuminate\Support\Carbon::parse($iso)->isoFormat('DD MMM HH:mm');
                        } catch (\Throwable) {
                            return '—';
                        }
                    };

                    // Hitos del ciclo de vida en orden cronológico; "cumplido" si tiene fecha registrada.
                    $milestones = [
                        ['label' => __('Assigned'), 'at' => $pg['assigned_at']],
                        ['label' => __('Prepared'), 'at' => $pg['prepared_at']],
                        ['label' => __('Loaded'), 'at' => $pg['loaded_at']],
                        ['label' => __('Delivered'), 'at' => $pg['delivered_at']],
                    ];

                    // Simbología VIGENTE (setting global): igual que el preview de EmittedLabelResource. Si el
                    // proveedor no está disponible, se degrada a solo QR para no romper la ficha.
                    $showQr = true;
                    $showBarcode = false;
                    try {
                        $symbology = app(\Logistics\Labels\Domain\Contract\LabelSymbologyProvider::class)->current(new \DateTimeImmutable);
                        $showQr = $symbology->includesQr();
                        $showBarcode = $symbology->includesBarcode();
                    } catch (\Throwable) {
                        // simbología no disponible: se muestra solo el QR por defecto.
                    }

                    $renderer = app(\Logistics\Labels\Presentation\Support\LabelPreviewRenderer::class);
                @endphp

                {{-- BLOQUE 1 · estado + hitos + línea de tiempo --}}
                <div class="ck-grid ck-grid--2 ck-section">
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">{{ __('Current status') }}</div>
                        <div class="ck-card__title" style="font-size:1.3rem">{{ $statusLabel }}</div>
                        <div class="ck-hub__pills" style="margin-top:.7rem">
                            @if($isDelivered)
                                <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>{{ __('Delivered') }}</span>
                            @elseif($tr)
                                <span class="ck-pill {{ $tr['position_is_live'] ? 'ck-pill--agent' : '' }}">
                                    <span class="ck-pill__dot"></span>{{ $tr['eta'] ? 'ETA '.$tr['eta'] : 'En seguimiento' }}
                                </span>
                                <span class="ck-pill">{{ $stopsText($tr) }}</span>
                            @endif
                        </div>
                        <dl class="ck-dl" style="margin-top:1rem">
                            @foreach($milestones as $ms)
                                <dt>{{ $ms['label'] }}</dt><dd>{{ $shortDt($ms['at']) }}</dd>
                            @endforeach
                        </dl>
                    </div>
                    <div class="ck-card">
                        <div class="ck-card__eyebrow">{{ __('Lifecycle') }}</div>
                        <div style="margin-top:.6rem"><x-cockpit.timeline :events="$this->timeline" /></div>
                    </div>
                </div>

                {{-- BLOQUE 2 · etiquetas emitidas con preview visual --}}
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">{{ __('Emitted labels') }}</div>
                    @if(count($this->labels))
                        {{-- Como máximo 3 por fila; una sola tarjeta ocupa un tercio, no el ancho completo. --}}
                        <div style="display:grid;gap:1rem;margin-top:.8rem;grid-template-columns:repeat(auto-fill,minmax(max(280px,calc((100% - 2rem) / 3)),1fr))">
                            @foreach($this->labels as $label)
                                @include('filament.shipments.partials.label-preview', [
                                    'label' => $label,
                                    'showQr' => $showQr,
                                    'showBarcode' => $showBarcode,
                                    'renderer' => $renderer,
                                ])
                            @endforeach
                        </div>
                    @else
                        <div class="ck-empty" style="margin-top:.6rem">
                            <div class="ck-empty__title">{{ __('No labels yet') }}</div>
                            <div class="ck-empty__hint">{{ __('No labels have been emitted for this order yet.') }}</div>
                        </div>
                    @endif
                </div>

                {{-- BLOQUE 3 · estado de entrega / ETA / vehículo --}}
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">{{ __('Delivery status') }}</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>{{ __('Assigned vehicle') }}</dt>
                        <dd>@if($del && !empty($del['vehicle_id']))<a href="{{ \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $del['vehicle_id']]) }}" wire:navigate class="ck-link">{{ $del['vehicle_plate'] }}</a>@else{{ $del && $del['vehicle_plate'] ? $del['vehicle_plate'] : '—' }}@endif</dd>
                        <dt>{{ __('Delivery status') }}</dt>
                        <dd>
                            @php [$dsl3, $dst3] = $del && $del['status'] ? ($deliveryStatusMap[strtoupper($del['status'])] ?? [ucfirst(strtolower($del['status'])), '']) : ['—', '']; @endphp
                            {{ $dsl3 }}
                        </dd>
                        <dt>ETA</dt><dd>{{ $tr && $tr['eta'] ? $tr['eta'] : '—' }}</dd>
                        <dt>{{ __('Remaining stops') }}</dt><dd>{{ $isDelivered ? 'Entregado' : $stopsText($tr) }}</dd>
                    </dl>
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
