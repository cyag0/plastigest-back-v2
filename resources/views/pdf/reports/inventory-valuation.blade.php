@extends('pdf.layout')
@use('App\Reports\Format', 'F')
@use('App\Reports\InventoryValuationReport', 'Valuation')

@section('title', $document->title())
@section('document-title', $document->title())

@section('styles')
    @include('pdf.reports.partials.styles')
@endsection

@section('content')
    @include('pdf.reports.partials.meta', ['periodLabel' => 'Corte', 'periodValue' => $as_of_label])

    <table class="kpis">
        <tr>
            <td>
                <div class="kpi-label">Valor del inventario</div>
                <div class="kpi-value">{{ F::money($totals['value']) }}</div>
                <div class="kpi-note">{{ count($locations) }} {{ count($locations) === 1 ? 'sucursal' : 'sucursales' }}</div>
            </td>
            <td>
                <div class="kpi-label">Productos con existencia</div>
                <div class="kpi-value">{{ number_format($totals['products']) }}</div>
                <div class="kpi-note">Producto por sucursal</div>
            </td>
            <td class="{{ $totals['out'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Agotados</div>
                <div class="kpi-value">{{ number_format($totals['out']) }}</div>
                <div class="kpi-note">Con mínimo y sin existencia</div>
            </td>
            <td>
                <div class="kpi-label">Bajo mínimo</div>
                <div class="kpi-value">{{ number_format($totals['low']) }}</div>
                <div class="kpi-note">Por debajo del mínimo</div>
            </td>
            <td class="{{ $totals['without_cost'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Sin costo</div>
                <div class="kpi-value">{{ number_format($totals['without_cost']) }}</div>
                <div class="kpi-note">Con existencia, sin valuar</div>
            </td>
        </tr>
    </table>

    @if(empty($locations))
        <div class="empty">No hay productos con existencia ni con mínimo configurado.</div>
    @else
        @if(count($locations) > 1)
            <table class="two-cols">
                <tr>
                    <td style="width: 58%">
                        <h2 class="section">Por sucursal</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th class="text-right">Productos</th>
                                    <th class="text-right">Valor</th>
                                    <th class="text-right">Agotados</th>
                                    <th class="text-right">Bajo mín.</th>
                                    <th class="text-right">Sin costo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($locations as $l)
                                    <tr>
                                        <td>{{ $l['name'] }}</td>
                                        <td class="text-right">{{ $l['products'] }}</td>
                                        <td class="text-right">{{ F::money($l['value']) }}</td>
                                        <td class="text-right {{ $l['out'] > 0 ? 'down' : '' }}">{{ $l['out'] }}</td>
                                        <td class="text-right">{{ $l['low'] }}</td>
                                        <td class="text-right">{{ $l['without_cost'] }}</td>
                                    </tr>
                                @endforeach
                                <tr class="total-row">
                                    <td>Total</td>
                                    <td class="text-right">{{ $totals['products'] }}</td>
                                    <td class="text-right">{{ F::money($totals['value']) }}</td>
                                    <td class="text-right">{{ $totals['out'] }}</td>
                                    <td class="text-right">{{ $totals['low'] }}</td>
                                    <td class="text-right">{{ $totals['without_cost'] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                    <td class="gap"></td>
                    <td>
                        <h2 class="section">Por categoría</h2>
                        <table>
                            <thead><tr><th>Categoría</th><th class="text-right">Valor</th><th class="text-right">%</th></tr></thead>
                            <tbody>
                                @foreach($categories as $c)
                                    <tr>
                                        <td>{{ $c['name'] }}</td>
                                        <td class="text-right">{{ F::money($c['value']) }}</td>
                                        <td class="text-right">{{ F::pct($totals['value'] > 0 ? $c['value'] / $totals['value'] : 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
            </table>
        @endif

        @foreach($locations as $i => $l)
            <h2 class="section {{ count($locations) > 1 ? 'page-break' : '' }}">
                {{ $l['name'] }} &nbsp;·&nbsp; {{ F::money($l['value']) }}
            </h2>
            <div class="section-note">
                {{ $l['products'] }} con existencia · {{ $l['out'] }} agotados · {{ $l['low'] }} bajo mínimo
                @if($l['without_cost'] > 0) · {{ $l['without_cost'] }} sin costo @endif
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%">Código</th>
                        <th>Producto</th>
                        <th style="width: 14%">Categoría</th>
                        <th class="text-right" style="width: 9%">Existencia</th>
                        <th class="text-right" style="width: 7%">Mínimo</th>
                        <th class="text-right" style="width: 9%">Costo unit.</th>
                        <th style="width: 12%">Origen del costo</th>
                        <th class="text-right" style="width: 10%">Valor</th>
                        <th style="width: 8%">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($l['rows'] as $r)
                        <tr class="{{ $r['status'] === Valuation::STATUS_OUT ? 'row-danger' : ($r['status'] === Valuation::STATUS_LOW ? 'row-warning' : '') }}">
                            <td>{{ $r['code'] }}</td>
                            <td>{{ $r['name'] }}</td>
                            <td>{{ $r['category'] }}</td>
                            <td class="text-right nowrap">{{ F::qty($r['stock']) }} {{ $r['unit'] }}</td>
                            <td class="text-right">{{ $r['minimum'] > 0 ? F::qty($r['minimum']) : '' }}</td>
                            <td class="text-right">{{ F::money($r['cost']) }}</td>
                            <td class="small {{ $r['cost'] === null ? 'down' : '' }}">{{ $r['cost_source'] }}</td>
                            <td class="text-right">{{ F::money($r['value']) }}</td>
                            <td class="small">{{ Valuation::STATUS_LABELS[$r['status']] }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7">Total {{ $l['name'] }}</td>
                        <td class="text-right">{{ F::money($l['value']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        @endforeach
    @endif

    <div class="footnote">
        Costo unitario: la última compra recibida{{ $historical ? ' hasta la fecha de corte' : '' }}, llevada a la unidad del producto; si nunca se ha comprado y se fabrica,
        lo que cuestan los insumos de su receta de empaque o de su fórmula; si no, el precio de compra del catálogo.
        "Incompleta": a la receta le falta el costo de algún insumo y el costo se quedó corto.
        Una existencia negativa no suma valor. No se listan los productos sin existencia y sin mínimo configurado.
        @if($historical) La existencia al corte sale del historial de movimientos; el mínimo es el configurado hoy. @endif
    </div>
@endsection
