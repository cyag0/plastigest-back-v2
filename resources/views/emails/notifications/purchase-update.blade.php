@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $notificationMessage }}</p>

    <div class="details">
        <table>
            <tr>
                <td>Proveedor</td>
                <td>{{ $supplier_name ?? '—' }}</td>
            </tr>
            <tr>
                <td>Compra #</td>
                <td>{{ $purchase_number ?? ($purchase->id ?? '—') }}</td>
            </tr>
            <tr>
                <td>Estado</td>
                <td>{{ $status_label ?? '—' }}</td>
            </tr>
            @if (!empty($total))
                <tr>
                    <td>Total</td>
                    <td>${{ number_format((float) $total, 2) }}</td>
                </tr>
            @endif
        </table>
    </div>

    @if (!empty($products) && count($products) > 0)
        <p><strong>Productos incluidos ({{ count($products) }}):</strong></p>
        <div class="details">
            <table>
                @foreach ($products as $p)
                    <tr>
                        <td>{{ data_get($p, 'name', data_get($p, 'product.name', '—')) }}</td>
                        <td>{{ data_get($p, 'quantity', '—') }} uds.</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
