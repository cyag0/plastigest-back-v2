<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>@yield('title', 'Documento PDF')</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #333;
            line-height: 1.4;
            padding: 20px;
            margin: 20px;
        }

        .header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #809671;
        }

        .company-name {
            font-size: 18pt;
            font-weight: bold;
            color: #809671;
            margin-bottom: 5px;
        }

        .document-title {
            font-size: 14pt;
            font-weight: bold;
            color: #725C3A;
            margin-top: 10px;
        }

        /* Marca de la app (GCStock) */
        .brand-header {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .brand-header td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }

        .brand-mark-cell {
            width: 50px;
            padding-right: 10px;
        }

        .brand-name {
            font-size: 18pt;
            font-weight: bold;
            color: #0F172A;
            line-height: 1;
        }

        .brand-name .brand-accent {
            color: #4F7A3A;
        }

        .brand-tagline {
            font-size: 8pt;
            color: #725C3A;
            margin-top: 2px;
        }

        .brand-meta {
            text-align: right;
        }

        .brand-meta .company-name {
            font-size: 13pt;
        }

        .info-section {
            background-color: #F4F1EA;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .info-row {
            display: flex;
            margin-bottom: 5px;
        }

        .info-label {
            font-weight: bold;
            width: 150px;
            color: #725C3A;
        }

        .info-value {
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 20px;
        }

        table thead {
            background-color: #809671;
            color: white;
        }

        table th {
            padding: 8px;
            text-align: left;
            font-weight: bold;
            font-size: 9pt;
            border: 1px solid #725C3A;
        }

        table td {
            padding: 6px 8px;
            border: 1px solid #E5E0D8;
            font-size: 9pt;
        }

        table tbody tr:nth-child(even) {
            background-color: #F4F1EA;
        }

        table tbody tr:hover {
            background-color: #E5E0D8;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-success {
            color: #809671;
            font-weight: bold;
        }

        .text-danger {
            color: #A65A4D;
            font-weight: bold;
        }

        .text-warning {
            color: #D2AB80;
            font-weight: bold;
        }

        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8pt;
            color: #725C3A;
            padding-top: 10px;
            border-top: 1px solid #E5E0D8;
        }

        .page-number:before {
            content: "Página " counter(page);
        }

        .summary-box {
            background-color: #F4F1EA;
            border-left: 4px solid #809671;
            padding: 10px;
            margin-top: 15px;
        }

        .summary-title {
            font-weight: bold;
            color: #725C3A;
            margin-bottom: 8px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
        }

        .badge-success {
            background-color: #E5E0D8;
            color: #809671;
        }

        .badge-danger {
            background-color: #E6DAC8;
            color: #A65A4D;
        }

        .badge-warning {
            background-color: #E5D2B8;
            color: #D2AB80;
        }

        .badge-info {
            background-color: #E5E0D8;
            color: #8B9FA8;
        }
    </style>
    @yield('styles')
</head>

<body>
    <div class="header">
        <table class="brand-header">
            <tr>
                <td class="brand-mark-cell">
                    @php
                        // Marca GCStock como SVG embebido (data-URI). dompdf renderiza
                        // SVG de forma fiable vía <img>, no como <svg> inline.
                        $gcstockMark = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 86 86">'
                            . '<rect width="86" height="86" rx="21" fill="#4F7A3A"/>'
                            . '<path d="M43 18 L68 30 L43 42 L18 30 Z" fill="#FFFFFF"/>'
                            . '<path d="M18 30 L43 42 L43 70 L18 58 Z" fill="#CFE3C2"/>'
                            . '<path d="M68 30 L43 42 L43 70 L68 58 Z" fill="#A9CE93"/>'
                            . '</svg>';
                    @endphp
                    <img src="data:image/svg+xml;base64,{{ base64_encode($gcstockMark) }}" width="44" height="44" alt="GCStock" />
                </td>
                <td>
                    <div class="brand-name">GC<span class="brand-accent">Stock</span></div>
                    <div class="brand-tagline">Gestión de inventario y sucursales</div>
                </td>
                <td class="brand-meta">
                    @if(!empty($company->name ?? null))
                        <div class="company-name">{{ $company->name }}</div>
                    @endif
                    <div class="document-title">@yield('document-title')</div>
                </td>
            </tr>
        </table>
    </div>

    @yield('content')

    <div class="footer">
        <div class="page-number"></div>
        <div>Generado con GCStock &middot; {{ now()->format('d/m/Y H:i:s') }}</div>
    </div>
</body>

</html>
