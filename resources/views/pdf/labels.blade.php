<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Etiquetas - {{ $title }}</title>
    <style>
        @page {
            size: 58mm auto;
            margin: 2mm;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            width: 58mm;
            font-size: 10pt;
        }
        .label {
            page-break-after: always;
            padding: 3mm;
            text-align: center;
            border: 1px dashed #ccc;
            margin-bottom: 2mm;
        }
        .label:last-child {
            page-break-after: auto;
            margin-bottom: 0;
        }
        .product-name {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 2mm;
            word-wrap: break-word;
        }
        .subtitle {
            font-size: 9pt;
            margin-bottom: 1mm;
        }
        .product-code {
            font-size: 9pt;
            margin-bottom: 2mm;
            color: #333;
        }
        .barcode-container {
            margin: 2mm 0;
        }
        .barcode-image {
            width: 100%;
            max-width: 50mm;
            height: auto;
        }
        .price {
            font-size: 12pt;
            font-weight: bold;
            margin-top: 2mm;
        }
    </style>
</head>
<body>
@for ($i = 0; $i < $quantity; $i++)
    <div class="label">
        <div class="product-name">{{ $title }}</div>
        @if ($subtitle)
            <div class="subtitle">{{ $subtitle }}</div>
        @endif
        <div class="product-code">Código: {{ $code }}</div>
        <div class="barcode-container">
            <img src="data:image/png;base64,{{ $barcode }}" alt="Barcode" class="barcode-image">
        </div>
        @if ($price)
            <div class="price">${{ number_format((float) $price, 2) }}</div>
        @endif
    </div>
@endfor
</body>
</html>
