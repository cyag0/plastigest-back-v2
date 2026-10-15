@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $alertMessage }}</p>

    <div class="details">
        <table>
            <tr>
                <td>Servidor</td>
                <td>{{ $serverUrl }}</td>
            </tr>
            <tr>
                <td>Fecha</td>
                <td>{{ $occurredAt }}</td>
            </tr>
        </table>
    </div>

    <p>El detalle está en los logs de la app (Coolify). Este aviso se manda como máximo una vez cada
        {{ $throttleMinutes }} minutos por tipo de falla.</p>
@endsection
