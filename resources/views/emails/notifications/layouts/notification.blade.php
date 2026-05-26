<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Notificación' }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F4F1EA;
            color: #333;
        }

        .wrapper {
            max-width: 600px;
            margin: 32px auto;
            background: #E6DAC8;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #E5E0D8;
            box-shadow: 0 8px 24px rgba(114, 92, 58, .12);
        }

        .header {
            padding: 24px 32px;
            background: #809671;
            border-bottom: 4px solid #D2AB80;
        }

        .header h1 {
            color: #fff;
            font-size: 20px;
            font-weight: 600;
        }

        .header .brand {
            color: #F4F1EA;
            font-size: 12px;
            margin-top: 4px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .badge-info {
            background: #E5D2B8;
            color: #725C3A;
        }

        .badge-success {
            background: #B3B792;
            color: #333;
        }

        .badge-warning {
            background: #D2AB80;
            color: #333;
        }

        .badge-error {
            background: #725C3A;
            color: #fff;
        }

        .badge-alert {
            background: #A65A4D;
            color: #fff;
        }

        .content {
            padding: 32px;
            background: #F4F1EA;
        }

        .content p {
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .details {
            background: #E5E0D8;
            border: 1px solid #D2AB80;
            border-radius: 6px;
            padding: 16px;
            margin: 16px 0;
        }

        .details table {
            width: 100%;
            border-collapse: collapse;
        }

        .details td {
            padding: 6px 0;
            font-size: 14px;
            vertical-align: top;
        }

        .details td:first-child {
            color: #725C3A;
            width: 40%;
        }

        .details td:last-child {
            font-weight: 500;
        }

        .footer {
            padding: 16px 32px;
            background: #E6DAC8;
            border-top: 1px solid #D2AB80;
            font-size: 12px;
            color: #725C3A;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="header">
            <h1>{{ $title ?? 'Notificación' }}</h1>
            <div class="brand">Plastigest — Sistema de Gestión</div>
        </div>
        <div class="content">
            @php
                $badgeClass = 'badge-' . ($severity ?? 'info');
            @endphp
            <span class="badge {{ $badgeClass }}">{{ ucfirst($severity ?? 'info') }}</span>

            @yield('body')
        </div>
        <div class="footer">
            Este mensaje fue generado automáticamente por Plastigest. No responder a este correo.
        </div>
    </div>
</body>

</html>
