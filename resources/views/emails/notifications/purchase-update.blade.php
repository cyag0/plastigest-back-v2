@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $message }}</p>

    <div class="details">
        <table>
            <tr>
                <td>Proveedor</td>
                <td>{{ $supplier_name ?? '—' }}</td>
            </tr>
            @if (!empty($purchase))
                <tr>
                    <td>Compra #</td>
                    <td>{{ $purchase->id }}</td>
                </tr>
            @endif
            @if (!empty($sub_type))
                <tr>
                    <td>Estado</td>
                    <td>{{ $sub_type === 'received' ? 'Recibida' : 'En Tránsito' }}</td>
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
