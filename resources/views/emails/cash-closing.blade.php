@extends('emails.notifications.layouts.notification')

@php
    $fmt = fn($v) => '$' . number_format((float) ($v ?? 0), 2);
    $diff = (float) ($closing->difference ?? 0);
@endphp

@section('body')
    <p>Hola{{ $recipientName ? ' ' . $recipientName : '' }},</p>

    <p>
        Se realizó el corte de caja de la sucursal
        <strong>{{ $closing->location->name ?? 'N/A' }}</strong>
        correspondiente al
        <strong>{{ \Carbon\Carbon::parse($closing->closing_date)->format('d/m/Y') }}</strong>.
        Encontrarás el reporte completo en el PDF adjunto.
    </p>

    <div class="details">
        <table>
            <tr>
                <td>Cerrado por</td>
                <td>{{ $closing->user->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Ingresos</td>
                <td>{{ $fmt($closing->total_income) }}</td>
            </tr>
            <tr>
                <td>Egresos</td>
                <td>-{{ $fmt($closing->total_expense) }}</td>
            </tr>
            <tr>
                <td>Saldo esperado</td>
                <td>{{ $fmt($closing->expected_balance) }}</td>
            </tr>
            @if($closing->physical_count !== null)
                <tr>
                    <td>Diferencia</td>
                    <td>{{ $diff >= 0 ? '+' : '' }}{{ $fmt($diff) }}</td>
                </tr>
            @endif
            <tr>
                <td>Movimientos</td>
                <td>{{ $closing->movements_count ?? 0 }}</td>
            </tr>
        </table>
    </div>

    <p>El PDF adjunto contiene el desglose por método de pago y el detalle de los movimientos del día.</p>
@endsection
