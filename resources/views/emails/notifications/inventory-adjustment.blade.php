@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $notificationMessage }}</p>

    <div class="details">
        <table>
            <tr>
                <td>Producto</td>
                <td>{{ $product?->name ?? '—' }}</td>
            </tr>
            <tr>
                <td>Ubicación</td>
                <td>{{ $location?->name ?? '—' }}</td>
            </tr>
            <tr>
                <td>Ajuste</td>
                <td>{{ ($adjustment_qty >= 0 ? '+' : '') . ($adjustment_qty ?? '—') }}</td>
            </tr>
            <tr>
                <td>Stock resultante</td>
                <td>{{ $new_stock ?? '—' }}</td>
            </tr>
            <tr>
                <td>Motivo</td>
                <td>{{ $reason ?? '—' }}</td>
            </tr>
            <tr>
                <td>Realizado por</td>
                <td>{{ $adjusted_by ?? '—' }}</td>
            </tr>
        </table>
    </div>
@endsection
