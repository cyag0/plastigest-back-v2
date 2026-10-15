<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>@yield('title')</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: {{ $paperWidthMm == 58 ? '7pt' : '8pt' }};
            color: #000;
            line-height: 1.35;
            /* Zona imprimible: el cabezal no llega a los bordes del rollo. */
            padding: 3mm {{ $paperWidthMm == 58 ? '2mm' : '4mm' }} 2mm;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .muted { color: #333; }
        .company { font-size: {{ $paperWidthMm == 58 ? '10pt' : '12pt' }}; font-weight: bold; }
        .title { font-size: {{ $paperWidthMm == 58 ? '9pt' : '10pt' }}; font-weight: bold; letter-spacing: 1px; margin-top: 2mm; }
        .rule { border-top: 1px dashed #000; margin: 2mm 0; }
        .rule-solid { border-top: 1px solid #000; margin: 2mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0.4mm 0; }
        .total-row td { font-size: {{ $paperWidthMm == 58 ? '10pt' : '12pt' }}; font-weight: bold; padding-top: 1mm; }
        .stamp {
            border: 2px solid #000; text-align: center; font-weight: bold;
            font-size: 11pt; padding: 1.5mm; margin: 2mm 0; letter-spacing: 2px;
        }
        .sign { margin-top: 10mm; border-top: 1px solid #000; text-align: center; padding-top: 1mm; }
        .small { font-size: {{ $paperWidthMm == 58 ? '6pt' : '7pt' }}; }
    </style>
</head>
<body>
    <div class="center">
        <div class="company">{{ $company?->name ?? 'GCStock' }}</div>
        @if($company?->business_name && $company->business_name !== $company->name)
            <div>{{ $company->business_name }}</div>
        @endif
        @if($company?->rfc)
            <div>RFC: {{ $company->rfc }}</div>
        @endif
        @if($location)
            <div class="bold">{{ $location->name }}</div>
            @if($location->address)<div class="small">{{ $location->address }}</div>@endif
            @if($location->phone)<div class="small">Tel. {{ $location->phone }}</div>@endif
        @endif
        <div class="title">@yield('heading')</div>
    </div>

    @yield('content')

    <div class="rule"></div>
    <div class="center small muted">Impreso {{ now()->format('d/m/Y H:i') }} · GCStock</div>
</body>
</html>
