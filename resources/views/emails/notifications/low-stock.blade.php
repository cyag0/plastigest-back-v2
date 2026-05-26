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
                <td>Stock actual</td>
                <td>{{ $current_stock ?? '—' }}</td>
            </tr>
            <tr>
                <td>Stock mínimo</td>
                <td>{{ $minimum_stock ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <p>Por favor ingresa al sistema y repone el stock lo antes posible.</p>
@endsection
