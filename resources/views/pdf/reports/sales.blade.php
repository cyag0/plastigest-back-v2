@extends('pdf.layout')
@use('App\Reports\Format', 'F')

@php
    $t = $totals;
    $p = $previous_totals;
    $topProducts = array_slice($products, 0, 25);
    $maxHour = max(array_column($by_hour, 'total') ?: [0]) ?: 1;
    $listLimit = 40;
@endphp

@section('title', $document->title())
@section('document-title', $document->title())

@section('styles')
    @include('pdf.reports.partials.styles')
@endsection

@section('content')
    @include('pdf.reports.partials.meta', [
        'periodValue' => $period->label(),
        'compareLabel' => $previous->label(),
    ])

    <table class="kpis">
        <tr>
            <td>
                <div class="kpi-label">Ventas</div>
                <div class="kpi-value">{{ F::money($t['total']) }}</div>
                <div class="kpi-note"><span class="{{ F::changeClass($t['total'], $p['total']) }}">{{ F::change($t['total'], $p['total']) }}</span>&nbsp; vs {{ F::money($p['total']) }}</div>
            </td>
            <td>
                <div class="kpi-label">Tickets</div>
                <div class="kpi-value">{{ number_format($t['tickets']) }}</div>
                <div class="kpi-note"><span class="{{ F::changeClass($t['tickets'], $p['tickets']) }}">{{ F::change($t['tickets'], $p['tickets']) }}</span>&nbsp; vs {{ number_format($p['tickets']) }}</div>
            </td>
            <td>
                <div class="kpi-label">Ticket promedio</div>
                <div class="kpi-value">{{ F::money($t['average']) }}</div>
                <div class="kpi-note"><span class="{{ F::changeClass($t['average'], $p['average']) }}">{{ F::change($t['average'], $p['average']) }}</span>&nbsp; vs {{ F::money($p['average']) }}</div>
            </td>
            <td>
                <div class="kpi-label">Descuentos</div>
                <div class="kpi-value">{{ F::money($t['discount']) }}</div>
                <div class="kpi-note">{{ $t['discounted'] }} {{ $t['discounted'] === 1 ? 'venta' : 'ventas' }} con descuento</div>
            </td>
            <td class="{{ $t['cancelled_count'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Cancelaciones</div>
                <div class="kpi-value">{{ number_format($t['cancelled_count']) }}</div>
                <div class="kpi-note">{{ F::money($t['cancelled_total']) }} cancelado</div>
            </td>
        </tr>
    </table>

    <h2 class="section">Comparativo contra el periodo anterior</h2>
    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="text-right">{{ $period->label() }}</th>
                <th class="text-right">{{ $previous->label() }}</th>
                <th class="text-right">Cambio</th>
            </tr>
        </thead>
        <tbody>
            @foreach([
                ['Ventas', $t['total'], $p['total'], true, true],
                ['Tickets', $t['tickets'], $p['tickets'], false, true],
                ['Ticket promedio', $t['average'], $p['average'], true, true],
                ['Descuentos', $t['discount'], $p['discount'], true, false],
                ['Cancelaciones', $t['cancelled_count'], $p['cancelled_count'], false, false],
                ['Importe cancelado', $t['cancelled_total'], $p['cancelled_total'], true, false],
            ] as [$label, $now, $before, $isMoney, $higherIsBetter])
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-right">{{ $isMoney ? F::money($now) : number_format($now) }}</td>
                    <td class="text-right">{{ $isMoney ? F::money($before) : number_format($before) }}</td>
                    <td class="text-right {{ F::changeClass($now, $before, $higherIsBetter) }}">{{ F::change($now, $before) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="two-cols">
        <tr>
            <td>
                <h2 class="section">Por sucursal</h2>
                @if(empty($by_location))
                    <div class="empty">Sin ventas en el periodo.</div>
                @else
                    <table>
                        <thead><tr><th>Sucursal</th><th class="text-right">Tickets</th><th class="text-right">Ventas</th><th class="text-right">%</th></tr></thead>
                        <tbody>
                            @foreach($by_location as $r)
                                <tr>
                                    <td>{{ $r['name'] }}</td>
                                    <td class="text-right">{{ number_format($r['tickets']) }}</td>
                                    <td class="text-right">{{ F::money($r['total']) }}</td>
                                    <td class="text-right">{{ F::pct($r['share']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
            <td class="gap"></td>
            <td>
                <h2 class="section">Métodos de pago</h2>
                @if(empty($payments))
                    <div class="empty">Sin cobros en el periodo.</div>
                @else
                    <table>
                        <thead><tr><th>Método</th><th class="text-right">Ventas</th><th class="text-right">Importe</th><th class="text-right">%</th></tr></thead>
                        <tbody>
                            @foreach($payments as $r)
                                <tr class="{{ $r['method'] === 'pending' ? 'row-warning' : '' }}">
                                    <td>{{ $r['label'] }}</td>
                                    <td class="text-right">{{ number_format($r['count']) }}</td>
                                    <td class="text-right">{{ F::money($r['total']) }}</td>
                                    <td class="text-right">{{ F::pct($r['share']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($abonos > 0)
                        <div class="footnote">Incluye {{ F::money($abonos) }} de abonos registrados en el periodo.</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    {{-- Un mes cabe lado a lado con las horas; más largo va a lo ancho, y de dos meses en adelante, por mes. --}}
    @php
        $longPeriod = count($by_day) > 62;
        $timeline = $longPeriod ? $by_month : $by_day;
        $maxTimeline = max(array_column($timeline, 'total') ?: [0]) ?: 1;
        $sideBySide = count($by_day) <= 35;
    @endphp
    @if($sideBySide)
        <table class="two-cols">
            <tr>
                <td>
                    @include('pdf.reports.partials.sales-timeline', ['title' => 'Por día', 'column' => 'Día', 'rows' => $by_day, 'max' => $maxTimeline])
                </td>
                <td class="gap"></td>
                <td>
                    @include('pdf.reports.partials.sales-timeline', ['title' => 'Por hora', 'column' => 'Hora', 'rows' => $by_hour, 'max' => $maxHour])
                </td>
            </tr>
        </table>
    @else
        @include('pdf.reports.partials.sales-timeline', ['title' => $longPeriod ? 'Por mes' : 'Por día', 'column' => $longPeriod ? 'Mes' : 'Día', 'rows' => $timeline, 'max' => $maxTimeline])
        @include('pdf.reports.partials.sales-timeline', ['title' => 'Por hora', 'column' => 'Hora', 'rows' => $by_hour, 'max' => $maxHour])
    @endif

    <h2 class="section">Productos más vendidos</h2>
    @if(empty($products))
        <div class="empty">Sin ventas en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 4%">#</th>
                    <th style="width: 12%">Código</th>
                    <th>Producto</th>
                    <th style="width: 17%">Categoría</th>
                    <th class="text-right" style="width: 12%">Cantidad</th>
                    <th class="text-right" style="width: 13%">Ventas</th>
                    <th class="text-right" style="width: 8%">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topProducts as $i => $r)
                    <tr>
                        <td class="text-center">{{ $i + 1 }}</td>
                        <td>{{ $r['code'] }}</td>
                        <td>{{ $r['name'] }}</td>
                        <td>{{ $r['category'] }}</td>
                        <td class="text-right nowrap">{{ F::qty($r['quantity']) }} {{ $r['unit'] }}{{ $r['quantity_complete'] ? '' : '*' }}</td>
                        <td class="text-right">{{ F::money($r['total']) }}</td>
                        <td class="text-right">{{ F::pct($r['share']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($products) > count($topProducts))
            <div class="footnote">Se muestran los {{ count($topProducts) }} de {{ count($products) }} productos vendidos; la lista completa está en el Excel.</div>
        @endif
        @if(collect($products)->contains(fn($p) => !$p['quantity_complete']))
            <div class="footnote">* Parte de lo vendido está en una unidad que no se puede convertir a la del producto y no se sumó a la cantidad.</div>
        @endif
    @endif

    <h2 class="section">Categorías</h2>
    @if(empty($categories))
        <div class="empty">Sin ventas en el periodo.</div>
    @else
        <table>
            <thead><tr><th>Categoría</th><th class="text-right">Productos</th><th class="text-right">Ventas</th><th class="text-right">%</th><th class="bar-cell"></th></tr></thead>
            <tbody>
                @foreach($categories as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="text-right">{{ $r['products'] }}</td>
                        <td class="text-right">{{ F::money($r['total']) }}</td>
                        <td class="text-right">{{ F::pct($r['share']) }}</td>
                        <td class="bar-cell">@if($r['share'] > 0)<div class="bar" style="width: {{ max(1, round($r['share'] * 100)) }}%"></div>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Ventas por cajero</h2>
    @if(empty($cashiers))
        <div class="empty">Sin ventas en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Cajero</th>
                    <th class="text-right">Tickets</th>
                    <th class="text-right">Ventas</th>
                    <th class="text-right">Ticket prom.</th>
                    <th class="text-right">Descuentos</th>
                    <th class="text-right">Cancel.</th>
                    <th class="text-right">Cancelado</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cashiers as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="text-right">{{ number_format($r['tickets']) }}</td>
                        <td class="text-right">{{ F::money($r['total']) }}</td>
                        <td class="text-right">{{ F::money($r['average']) }}</td>
                        <td class="text-right">{{ F::money($r['discount']) }}</td>
                        <td class="text-right {{ $r['cancelled_count'] > 0 ? 'down' : '' }}">{{ $r['cancelled_count'] }}</td>
                        <td class="text-right">{{ F::money($r['cancelled_total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Cancelaciones</h2>
    @if(empty($cancellations))
        <div class="empty">No hubo ventas canceladas en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Venta</th>
                    <th>Sucursal</th>
                    <th>Cajero</th>
                    <th class="text-right">Importe</th>
                    <th>Canceló</th>
                    <th>Cancelada</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($cancellations, 0, $listLimit) as $r)
                    <tr>
                        <td class="nowrap">{{ $r['number'] }}</td>
                        <td class="nowrap">{{ F::dateTime($r['date']) }}</td>
                        <td>{{ $r['location'] }}</td>
                        <td>{{ $r['cashier'] }}</td>
                        <td class="text-right">{{ F::money($r['total']) }}</td>
                        <td>{{ $r['cancelled_by'] ?? '—' }}</td>
                        <td class="nowrap">{{ F::dateTime($r['cancelled_at']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($cancellations) > $listLimit)
            <div class="footnote">Se muestran {{ $listLimit }} de {{ count($cancellations) }} cancelaciones; la lista completa está en el Excel.</div>
        @endif
    @endif

    <h2 class="section">Descuentos más altos</h2>
    @if(empty($discounts))
        <div class="empty">No hubo ventas con descuento en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Cajero</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right">Descuento</th>
                    <th class="text-right">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($discounts, 0, $listLimit) as $r)
                    <tr>
                        <td class="nowrap">{{ $r['number'] }}</td>
                        <td class="nowrap">{{ F::dateTime($r['date']) }}</td>
                        <td>{{ $r['location'] }}</td>
                        <td>{{ $r['cashier'] }}</td>
                        <td class="text-right">{{ F::money($r['subtotal']) }}</td>
                        <td class="text-right">{{ F::money($r['discount']) }}</td>
                        <td class="text-right">{{ F::pct($r['share']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($discounts) > $listLimit)
            <div class="footnote">Se muestran los {{ $listLimit }} descuentos más altos de {{ count($discounts) }}; la lista completa está en el Excel.</div>
        @endif
    @endif

    <div class="footnote">Las ventas canceladas no cuentan en ventas, tickets ni métodos de pago. Los métodos de pago son lo cobrado en el periodo: lo pagado al vender y los abonos registrados en el periodo, aunque la venta sea anterior. "Por cobrar" es lo que sigue pendiente de las ventas del periodo (crédito o anticipo).</div>
@endsection
