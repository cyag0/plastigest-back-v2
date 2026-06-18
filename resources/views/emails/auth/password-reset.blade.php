@extends('emails.notifications.layouts.notification')

@section('body')
    <p>Hola {{ $userName }},</p>

    <p>
        Recibimos una solicitud para restablecer la contraseña de tu cuenta.
        Usa el siguiente código para continuar con el proceso:
    </p>

    <div class="details" style="text-align: center;">
        <div style="font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #725C3A;">
            {{ $code }}
        </div>
    </div>

    <p>
        Este código vence en <strong>{{ $minutes }} minutos</strong>. Por seguridad,
        no lo compartas con nadie.
    </p>

    <p>
        Si no solicitaste restablecer tu contraseña, puedes ignorar este correo;
        tu contraseña actual seguirá siendo válida.
    </p>
@endsection
