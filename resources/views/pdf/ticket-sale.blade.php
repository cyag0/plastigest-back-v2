@extends('pdf.ticket-layout')

@php
    $money = fn($v) => '$' . number_format((float) $v, 2);
    $qty = fn($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $methods = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'mixed' => 'Mixto'];
    $cancelled = ($sale->status?->value ?? $sale->status) === 'cancelled';
@endphp

@section('title', 'Ticket ' . $sale->sale_number)
@section('heading', 'TICKET DE VENTA')

@section('content')
    <table style="margin-top: 2mm">
        <tr><td>Folio</td><td class="right bold">{{ $sale->sale_number }}</td></tr>
        <tr><td>Fecha</td><td class="right">{{ $sale->created_at?->format('d/m/Y H:i') }}</td></tr>
        @if($sale->cashRegister)
            <tr><td>Caja</td><td class="right">{{ $sale->cashRegister->name }}</td></tr>
        @endif
        <tr><td>Atendió</td><td class="right">{{ $sale->user?->name ?? '—' }}</td></tr>
        @if($sale->customer)
            <tr><td>Cliente</td><td class="right">{{ $sale->customer->name }}</td></tr>
        @endif
    </table>

    @if($cancelled)
        <div class="stamp">CANCELADA</div>
    @endif

    <div class="rule"></div>
    <table>
        @foreach($sale->details as $d)
            @php $unit = $d->package?->package_name ?? $d->unit?->abbreviation ?? ''; @endphp
            <tr>
                <td colspan="2" class="bold">{{ $d->product?->name ?? 'Producto' }}</td>
            </tr>
            <tr>
                <td class="muted">{{ $qty($d->quantity) }} {{ $unit }} × {{ $money($d->unit_price) }}</td>
                <td class="right">{{ $money($d->subtotal) }}</td>
            </tr>
            @if((float) $d->discount > 0)
                <tr><td class="muted">&nbsp;&nbsp;Descuento</td><td class="right">−{{ $money($d->discount) }}</td></tr>
            @endif
        @endforeach
    </table>

    <div class="rule"></div>
    @php $items = $sale->details->sum(fn($d) => (float) $d->quantity); @endphp
    <table>
        <tr><td>Artículos</td><td class="right">{{ $qty($items) }}</td></tr>
        <tr><td>Subtotal</td><td class="right">{{ $money($sale->subtotal) }}</td></tr>
        @if((float) $sale->discount > 0)
            <tr><td>Descuento</td><td class="right">−{{ $money($sale->discount) }}</td></tr>
        @endif
        <tr class="total-row"><td>TOTAL</td><td class="right">{{ $money($sale->total) }}</td></tr>
    </table>

    <div class="rule"></div>
    <table>
        @forelse($sale->payments as $p)
            <tr><td>{{ $methods[$p->method] ?? ucfirst($p->method) }}</td><td class="right">{{ $money($p->amount) }}</td></tr>
            @if($p->method === 'cash' && $p->tendered !== null && (float) $p->tendered > (float) $p->amount)
                <tr><td class="muted">&nbsp;&nbsp;Recibido</td><td class="right">{{ $money($p->tendered) }}</td></tr>
                <tr><td class="bold">&nbsp;&nbsp;Cambio</td><td class="right bold">{{ $money($p->change) }}</td></tr>
            @endif
        @empty
            <tr><td>{{ $methods[$sale->payment_method] ?? 'Pago' }}</td><td class="right">{{ $money($sale->paid_amount) }}</td></tr>
        @endforelse
        @if($sale->payment_status !== 'paid' && !$cancelled)
            <tr><td class="bold">Saldo pendiente</td><td class="right bold">{{ $money((float) $sale->total - (float) $sale->paid_amount) }}</td></tr>
        @endif
    </table>

    @if($sale->notes)
        <div class="rule"></div>
        <div class="small">{{ $sale->notes }}</div>
    @endif

    <div class="rule"></div>
    <div class="center bold">¡Gracias por su compra!</div>
    <div class="center small muted">Este ticket no es un comprobante fiscal.</div>
@endsection
