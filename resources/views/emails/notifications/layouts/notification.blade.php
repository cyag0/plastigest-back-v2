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
            background: #f5f5f5;
            color: #333;
        }

        .wrapper {
            max-width: 600px;
            margin: 32px auto;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .header {
            padding: 24px 32px;
            background: #1e293b;
        }

        .header h1 {
            color: #fff;
            font-size: 20px;
            font-weight: 600;
        }

        .header .brand {
            color: #94a3b8;
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
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-success {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-warning {
            background: #fef9c3;
            color: #a16207;
        }

        .badge-error {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge-alert {
            background: #fce7f3;
            color: #be185d;
        }

        .content {
            padding: 32px;
        }

        .content p {
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .details {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
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
            color: #64748b;
            width: 40%;
        }

        .details td:last-child {
            font-weight: 500;
        }

        .footer {
            padding: 16px 32px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #94a3b8;
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
