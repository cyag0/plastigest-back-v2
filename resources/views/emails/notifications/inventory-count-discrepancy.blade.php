@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $notificationMessage }}</p>

    <div class="details">
        <table>
            <tr>
                <td>Ubicación</td>
                <td>{{ $location?->name ?? '—' }}</td>
            </tr>
            <tr>
                <td>Discrepancias</td>
                <td>{{ $discrepancies_count ?? 0 }} productos</td>
            </tr>
        </table>
    </div>

    @if (!empty($discrepancies))
        <p><strong>Detalle de discrepancias:</strong></p>
        <div class="details">
            <table>
                <tr>
                    <td><strong>Producto</strong></td>
                    <td><strong>Sistema</strong></td>
                    <td><strong>Contado</strong></td>
                    <td><strong>Diferencia</strong></td>
                </tr>
                @foreach ($discrepancies as $d)
                    <tr>
                        <td>{{ data_get($d, 'product_name', '—') }}</td>
                        <td>{{ data_get($d, 'system_stock', '—') }}</td>
                        <td>{{ data_get($d, 'counted_stock', '—') }}</td>
                        <td>{{ data_get($d, 'difference', '—') }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <p>Por favor revisa el conteo y aplica los ajustes necesarios.</p>
@endsection
