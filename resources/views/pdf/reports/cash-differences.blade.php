@extends('pdf.layout')
@use('App\Reports\Format', 'F')
@use('App\Reports\CashDifferencesReport', 'Report')

@php
    $t = $totals;
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
            <td>
                <div class="kpi-label">Turnos cerrados</div>
                <div class="kpi-value">{{ number_format($t['sessions']) }}</div>
                <div class="kpi-note">{{ $t['exact'] }} sin diferencia</div>
            </td>
            <td class="{{ $t['short_count'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Faltantes</div>
                <div class="kpi-value">{{ F::money($t['short_total']) }}</div>
                <div class="kpi-note">en {{ $t['short_count'] }} {{ $t['short_count'] === 1 ? 'turno' : 'turnos' }}</div>
            </td>
            <td>
                <div class="kpi-label">Sobrantes</div>
                <div class="kpi-value">{{ F::money($t['over_total']) }}</div>
                <div class="kpi-note">en {{ $t['over_count'] }} {{ $t['over_count'] === 1 ? 'turno' : 'turnos' }}</div>
            </td>
            <td>
                <div class="kpi-label">Neto</div>
                <div class="kpi-value">{{ F::money($t['net']) }}</div>
                <div class="kpi-note">Contado menos esperado</div>
            </td>
            <td class="{{ $t['flagged'] > 0 ? 'alert' : '' }}">
                <div class="kpi-label">Cajeros a revisar</div>
                <div class="kpi-value">{{ $t['flagged'] }}</div>
                <div class="kpi-note">Con faltantes repetidos</div>
            </td>
        </tr>
    </table>

    <h2 class="section">Por cajero</h2>
    @if(empty($cashiers))
        <div class="empty">No se cerraron turnos en el periodo.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Cajero</th>
                    <th class="text-right">Turnos</th>
                    <th class="text-right">Con faltante</th>
                    <th class="text-right">Total faltante</th>
                    <th class="text-right">Mayor faltante</th>
                    <th class="text-right">Con sobrante</th>
                    <th class="text-right">Total sobrante</th>
                    <th class="text-right">Neto</th>
                    <th class="text-right">Con diferencia</th>
                    <th>Atención</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cashiers as $c)
                    <tr class="{{ $c['flagged'] ? 'row-danger' : '' }}">
                        <td>{{ $c['name'] }}</td>
                        <td class="text-right">{{ $c['sessions'] }}</td>
                        <td class="text-right">{{ $c['short_count'] }}</td>
                        <td class="text-right {{ $c['short_total'] < 0 ? 'down' : '' }}">{{ F::money($c['short_total']) }}</td>
                        <td class="text-right">{{ $c['worst_short'] < 0 ? F::money($c['worst_short']) : '' }}</td>
                        <td class="text-right">{{ $c['over_count'] }}</td>
                        <td class="text-right">{{ F::money($c['over_total']) }}</td>
                        <td class="text-right">{{ F::money($c['net']) }}</td>
                        <td class="text-right">{{ F::pct($c['with_difference'], 0) }}</td>
                        <td class="small">{{ $c['flagged'] ? 'Faltantes repetidos' : '' }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>Total</td>
                    <td class="text-right">{{ $t['sessions'] }}</td>
                    <td class="text-right">{{ $t['short_count'] }}</td>
                    <td class="text-right">{{ F::money($t['short_total']) }}</td>
                    <td></td>
                    <td class="text-right">{{ $t['over_count'] }}</td>
                    <td class="text-right">{{ F::money($t['over_total']) }}</td>
                    <td class="text-right">{{ F::money($t['net']) }}</td>
                    <td></td><td></td>
                </tr>
            </tbody>
        </table>
        <div class="footnote">
            "Faltantes repetidos": {{ Report::REPEATED_SHORTAGES }} faltantes o más en el periodo, o en al menos la mitad de sus turnos.
            Un sobrante también es una diferencia: puede ser un cobro sin registrar o cambio mal dado.
        </div>
    @endif

    <h2 class="section">Turnos con diferencia</h2>
    @if(empty($listed_differences))
        <div class="empty">Todos los turnos del periodo cuadraron.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 6%">Turno</th>
                    <th style="width: 12%">Cerró</th>
                    <th style="width: 14%">Sucursal</th>
                    <th style="width: 10%">Caja</th>
                    <th style="width: 14%">Cajero</th>
                    <th class="text-right" style="width: 9%">Esperado</th>
                    <th class="text-right" style="width: 9%">Contado</th>
                    <th class="text-right" style="width: 9%">Diferencia</th>
                    <th>Notas</th>
                </tr>
            </thead>
            <tbody>
                @foreach($listed_differences as $s)
                    <tr class="{{ $s['difference'] < 0 ? 'row-danger' : 'row-warning' }}">
                        <td>#{{ $s['id'] }}</td>
                        <td class="nowrap">{{ F::dateTime($s['closed_at']) }}</td>
                        <td>{{ $s['location'] }}</td>
                        <td>{{ $s['register'] }}</td>
                        <td>{{ $s['cashier'] }}</td>
                        <td class="text-right">{{ F::money($s['expected']) }}</td>
                        <td class="text-right">{{ F::money($s['counted']) }}</td>
                        <td class="text-right {{ $s['difference'] < 0 ? 'down' : '' }}">{{ ($s['difference'] > 0 ? '+' : '') . F::money($s['difference']) }}</td>
                        <td class="small">{{ $s['notes'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($with_difference_count > count($listed_differences))
            <div class="footnote">Se muestran los {{ count($listed_differences) }} turnos con mayor diferencia de {{ number_format($with_difference_count) }}; la lista completa está en el Excel, con los {{ number_format(count($sessions)) }} turnos del periodo.</div>
        @else
            <div class="footnote">Los {{ count($sessions) }} turnos del periodo, incluidos los que cuadraron, están en el Excel.</div>
        @endif
    @endif
@endsection
