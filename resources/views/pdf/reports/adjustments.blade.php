@extends('pdf.layout')
@use('App\Reports\Format', 'F')

@php
    $t = $totals;
    $detailLimit = 150;
@endphp

@section('title', $document->title())
@section('document-title', $document->title())

@section('styles')
    @include('pdf.reports.partials.styles')
@endsection

@section('content')
    @include('pdf.reports.partials.meta', ['periodValue' => $period->label()])

    <table class="kpis">
        <tr>
            <td class="{{ $t['loss_cost'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Pérdidas</div>
                <div class="kpi-value">{{ F::money($t['loss_cost']) }}</div>
                <div class="kpi-note">{{ $t['loss_count'] }} {{ $t['loss_count'] === 1 ? 'salida' : 'salidas' }} de inventario</div>
            </td>
            <td>
                <div class="kpi-label">Recuperado</div>
                <div class="kpi-value">{{ F::money($t['gain_cost']) }}</div>
                <div class="kpi-note">{{ $t['gain_count'] }} {{ $t['gain_count'] === 1 ? 'entrada' : 'entradas' }} (encontrado, conteo)</div>
            </td>
            <td>
                <div class="kpi-label">Neto</div>
                <div class="kpi-value">{{ F::money($t['net']) }}</div>
                <div class="kpi-note">Recuperado menos pérdidas</div>
            </td>
            <td>
                <div class="kpi-label">Motivo principal</div>
                <div class="kpi-value" style="font-size: 11pt">{{ $by_reason[0]['name'] ?? '—' }}</div>
                <div class="kpi-note">{{ isset($by_reason[0]) ? F::money($by_reason[0]['cost']) . ' · ' . F::pct($by_reason[0]['share']) : 'Sin pérdidas' }}</div>
            </td>
        </tr>
    </table>

    <h2 class="section">Pérdidas por motivo</h2>
    @if(empty($by_reason))
        <div class="empty">No hubo pérdidas en el periodo.</div>
    @else
        <table>
            <thead><tr><th>Motivo</th><th class="text-right" style="width: 12%">Registros</th><th class="text-right" style="width: 14%">Costo</th><th class="text-right" style="width: 10%">%</th><th class="bar-cell" style="width: 22%"></th></tr></thead>
            <tbody>
                @foreach($by_reason as $g)
                    <tr>
                        <td>{{ $g['name'] }}</td>
                        <td class="text-right">{{ $g['count'] }}</td>
                        <td class="text-right">{{ F::money($g['cost']) }}</td>
                        <td class="text-right">{{ F::pct($g['share']) }}</td>
                        <td class="bar-cell">@if($g['share'] > 0)<div class="bar" style="width: {{ max(1, round($g['share'] * 100)) }}%; background: #E3A79B"></div>@endif</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>Total</td>
                    <td class="text-right">{{ $t['loss_count'] }}</td>
                    <td class="text-right">{{ F::money($t['loss_cost']) }}</td>
                    <td></td><td></td>
                </tr>
            </tbody>
        </table>
    @endif

    @if(!empty($gains_by_reason))
        <h2 class="section">Entradas por ajuste</h2>
        <table>
            <thead><tr><th>Motivo</th><th class="text-right" style="width: 12%">Registros</th><th class="text-right" style="width: 14%">Costo</th></tr></thead>
            <tbody>
                @foreach($gains_by_reason as $g)
                    <tr>
                        <td>{{ $g['name'] }}</td>
                        <td class="text-right">{{ $g['count'] }}</td>
                        <td class="text-right">{{ F::money($g['cost']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Por sucursal</h2>
    @if(empty($by_location))
        <div class="empty">Sin ajustes en el periodo.</div>
    @else
        <table>
            <thead><tr><th>Sucursal</th><th class="text-right" style="width: 12%">Pérdidas</th><th class="text-right" style="width: 14%">Costo</th><th class="text-right" style="width: 14%">Recuperado</th><th class="text-right" style="width: 14%">Neto</th></tr></thead>
            <tbody>
                @foreach($by_location as $g)
                    <tr>
                        <td>{{ $g['name'] }}</td>
                        <td class="text-right">{{ $g['loss_count'] }}</td>
                        <td class="text-right">{{ F::money($g['loss_cost']) }}</td>
                        <td class="text-right">{{ F::money($g['gain_cost']) }}</td>
                        <td class="text-right {{ $g['net'] < 0 ? 'down' : '' }}">{{ F::money($g['net']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Por usuario que registró</h2>
    @if(empty($by_user))
        <div class="empty">No hubo pérdidas en el periodo.</div>
    @else
        <table>
            <thead><tr><th>Usuario</th><th class="text-right" style="width: 12%">Registros</th><th class="text-right" style="width: 14%">Costo</th><th class="text-right" style="width: 10%">%</th></tr></thead>
            <tbody>
                @foreach($by_user as $g)
                    <tr>
                        <td>{{ $g['name'] }}</td>
                        <td class="text-right">{{ $g['count'] }}</td>
                        <td class="text-right">{{ F::money($g['cost']) }}</td>
                        <td class="text-right">{{ F::pct($g['share']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="section">Productos con más pérdida</h2>
    @if(empty($by_product))
        <div class="empty">No hubo pérdidas en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 4%">#</th>
                    <th style="width: 12%">Código</th>
                    <th>Producto</th>
                    <th class="text-right" style="width: 12%">Cantidad</th>
                    <th class="text-right" style="width: 9%">Registros</th>
                    <th class="text-right" style="width: 12%">Costo</th>
                    <th class="text-right" style="width: 8%">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($by_product, 0, 30) as $i => $g)
                    <tr>
                        <td class="text-center">{{ $i + 1 }}</td>
                        <td>{{ $g['code'] }}</td>
                        <td>{{ $g['name'] }}</td>
                        <td class="text-right nowrap">{{ F::qty($g['quantity']) }} {{ $g['unit'] }}</td>
                        <td class="text-right">{{ $g['count'] }}</td>
                        <td class="text-right">{{ F::money($g['cost']) }}</td>
                        <td class="text-right">{{ F::pct($g['share']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($by_product) > 30)
            <div class="footnote">Se muestran 30 de {{ count($by_product) }} productos; la lista completa está en el Excel.</div>
        @endif
    @endif

    <h2 class="section page-break">Detalle de ajustes</h2>
    @if(empty($detail))
        <div class="empty">Sin ajustes en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 11%">Fecha</th>
                    <th style="width: 13%">Sucursal</th>
                    <th>Producto</th>
                    <th style="width: 13%">Motivo</th>
                    <th class="text-right" style="width: 9%">Cantidad</th>
                    <th class="text-right" style="width: 9%">Costo</th>
                    <th style="width: 12%">Usuario</th>
                    <th style="width: 18%">Notas</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($detail, 0, $detailLimit) as $r)
                    <tr class="{{ $r['direction'] === 'in' ? 'row-muted' : '' }}">
                        <td class="nowrap">{{ F::dateTime($r['date']) }}</td>
                        <td>{{ $r['location'] }}</td>
                        <td>{{ $r['product'] }}</td>
                        <td>{{ $r['reason_label'] }}</td>
                        <td class="text-right nowrap">{{ $r['direction'] === 'in' ? '+' : '−' }}{{ F::qty($r['quantity']) }} {{ $r['unit'] }}</td>
                        <td class="text-right">{{ $r['cost'] === null ? '—' : ($r['direction'] === 'in' ? '+' : '−') . F::money($r['cost']) }}</td>
                        <td>{{ $r['user'] }}</td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($r['notes'] ?? '', 90) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($detail) > $detailLimit)
            <div class="footnote">Se muestran {{ $detailLimit }} de {{ count($detail) }} ajustes; el detalle completo está en el Excel.</div>
        @endif
    @endif

    <div class="footnote">
        Incluye los ajustes manuales, las mermas de producción y las diferencias de los conteos físicos.
        Costo: la última compra recibida hasta el fin del periodo (o, si se fabrica, lo que cuestan sus insumos; si no, el precio del catálogo).
        @if($t['without_cost'] > 0) {{ $t['without_cost'] }} {{ $t['without_cost'] === 1 ? 'ajuste no tiene' : 'ajustes no tienen' }} costo y no suman. @endif
    </div>
@endsection
