@extends('pdf.ticket-layout')

@php
    $money = fn($v) => ((float) $v < 0 ? '−' : '') . '$' . number_format(abs((float) $v), 2);
    $methods = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia'];
    $open = $session->status === 'open';
    $byMethod = $summary['by_method'] ?? [];
    $difference = (float) ($session->difference ?? 0);
@endphp

@section('title', 'Corte ' . ($session->cashRegister?->name ?? 'de caja'))
@section('heading', $open ? 'CORTE PARCIAL' : 'CORTE DE CAJA')

@section('content')
    <table style="margin-top: 2mm">
        <tr><td>Caja</td><td class="right bold">{{ $session->cashRegister?->name ?? '—' }}</td></tr>
        <tr><td>Turno</td><td class="right">#{{ $session->id }}</td></tr>
        <tr><td>Cajero</td><td class="right">{{ $session->user?->name ?? '—' }}</td></tr>
        <tr><td>Apertura</td><td class="right">{{ $session->opened_at?->format('d/m/Y H:i') }}</td></tr>
        @if(!$open)
            <tr><td>Cierre</td><td class="right">{{ $session->closed_at?->format('d/m/Y H:i') }}</td></tr>
            @if($session->closedBy && $session->closedBy->id !== $session->user_id)
                <tr><td>Cerró</td><td class="right">{{ $session->closedBy->name }}</td></tr>
            @endif
        @endif
    </table>

    @if($open)
        <div class="stamp" style="font-size: 9pt">TURNO ABIERTO</div>
    @endif

    <div class="rule"></div>
    <div class="bold">VENTAS</div>
    <table>
        <tr><td>Ventas cobradas</td><td class="right">{{ (int) ($summary['sales_count'] ?? 0) }}</td></tr>
        <tr><td>Total vendido</td><td class="right bold">{{ $money($summary['sales_total'] ?? 0) }}</td></tr>
        @if(($summary['cancelled_count'] ?? 0) > 0)
            <tr><td>Canceladas</td><td class="right">{{ (int) $summary['cancelled_count'] }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>
    <div class="bold">POR FORMA DE PAGO</div>
    <table>
        @forelse($byMethod as $method => $t)
            <tr><td class="bold" colspan="2">{{ $methods[$method] ?? ucfirst((string) $method) }}</td></tr>
            <tr><td class="muted">&nbsp;&nbsp;Entradas</td><td class="right">{{ $money($t['income'] ?? 0) }}</td></tr>
            @if((float) ($t['expense'] ?? 0) > 0)
                <tr><td class="muted">&nbsp;&nbsp;Salidas</td><td class="right">−{{ $money($t['expense']) }}</td></tr>
            @endif
            <tr><td class="muted">&nbsp;&nbsp;Neto</td><td class="right">{{ $money($t['net'] ?? 0) }}</td></tr>
        @empty
            <tr><td class="muted" colspan="2">Sin movimientos</td></tr>
        @endforelse
    </table>

    {{-- Gastos, compras, devoluciones y movimientos a mano en efectivo, con
         quién los registró: el dinero que otro sacó del cajón queda a la vista.
         Los cortes cerrados antes de esto no lo traen. --}}
    @if(!empty($summary['cash_movements']))
        <div class="rule"></div>
        <div class="bold">OTROS MOVIMIENTOS DE EFECTIVO</div>
        <table>
            @foreach($summary['cash_movements'] as $m)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit($m['concept'] ?? '', 40) }}</td>
                    <td class="right">{{ ($m['type'] ?? '') === 'expense' ? '−' : '' }}{{ $money($m['amount'] ?? 0) }}</td>
                </tr>
                @if(!empty($m['user_name']))
                    <tr><td class="muted" colspan="2">&nbsp;&nbsp;{{ $m['user_name'] }}</td></tr>
                @endif
            @endforeach
        </table>
    @endif

    <div class="rule-solid"></div>
    <div class="bold">EFECTIVO EN CAJA</div>
    <table>
        <tr><td>Fondo inicial</td><td class="right">{{ $money($summary['opening_amount'] ?? $session->opening_amount) }}</td></tr>
        <tr><td>+ Neto en efectivo</td><td class="right">{{ $money($byMethod['cash']['net'] ?? 0) }}</td></tr>
        <tr class="total-row"><td>Esperado</td><td class="right">{{ $money($summary['expected_cash'] ?? 0) }}</td></tr>
        @if(!$open)
            <tr><td>Contado</td><td class="right bold">{{ $money($session->counted_cash) }}</td></tr>
        @endif
    </table>

    @if(!$open)
        <div class="stamp">
            @if(abs($difference) < 0.005)
                CUADRÓ
            @elseif($difference > 0)
                SOBRANTE {{ $money($difference) }}
            @else
                FALTANTE {{ $money(abs($difference)) }}
            @endif
        </div>
    @else
        <table>
            <tr><td>Contado</td><td class="right">$ ____________</td></tr>
        </table>
    @endif

    @if($session->notes)
        <div class="small"><span class="bold">Notas:</span> {{ $session->notes }}</div>
    @endif

    <table style="margin-top: 4mm">
        <tr>
            <td style="width: 48%"><div class="sign small">Cajero<br>{{ $session->user?->name }}</div></td>
            <td style="width: 4%"></td>
            <td style="width: 48%"><div class="sign small">Recibe / Encargado</div></td>
        </tr>
    </table>
@endsection
