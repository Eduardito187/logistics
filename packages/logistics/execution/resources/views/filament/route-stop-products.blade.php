{{--
    Preview de los productos del pedido de una parada (ciclo de reparto): miniaturas CIRCULARES con el
    nombre + SKU del producto (regla dura: nunca un producto sin identificar). Un producto con cantidad ≥ 2
    lleva un badge «x2» arriba a la derecha (como el contador de una bolsa). Sin imagen, muestra las iniciales.
    Las URLs de imagen son CONTENIDO del catálogo (no una librería/fuente de CDN): no infringen la regla CERO CDN.
--}}
@php
    /** @var list<array{name: string, sku: string, image: string|null, qty: int}> $products */
    $products = $products ?? [];
@endphp

@if (! empty($products))
    <div class="fi-route-stop-products">
        <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Products') }}</div>
        <div class="flex flex-wrap gap-3">
            @foreach ($products as $product)
                @php
                    $initials = \Illuminate\Support\Str::of($product['name'])->trim()->substr(0, 2)->upper();
                    $title = $product['name'].' · SKU '.$product['sku'].($product['qty'] > 1 ? ' · x'.$product['qty'] : '');
                @endphp
                <div class="flex w-16 flex-col items-center gap-1" title="{{ $title }}">
                    <div class="relative">
                        @if ($product['image'])
                            <img
                                src="{{ $product['image'] }}"
                                alt="{{ $product['name'].' · SKU '.$product['sku'] }}"
                                loading="lazy"
                                class="h-12 w-12 rounded-full object-cover ring-1 ring-gray-200 dark:ring-white/10"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            />
                            <div class="hidden h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold uppercase text-gray-500 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                                {{ $initials }}
                            </div>
                        @else
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold uppercase text-gray-500 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                                {{ $initials }}
                            </div>
                        @endif

                        @if ($product['qty'] > 1)
                            <span class="absolute -right-1 -top-1 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-primary-600 px-1 text-[10px] font-bold leading-4 text-white ring-2 ring-white dark:ring-gray-900">
                                x{{ $product['qty'] }}
                            </span>
                        @endif
                    </div>
                    <span class="w-full truncate text-center text-[10px] leading-tight text-gray-600 dark:text-gray-300">{{ $product['name'] }}</span>
                    <span class="w-full truncate text-center text-[10px] leading-tight text-gray-400 dark:text-gray-500">{{ $product['sku'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
