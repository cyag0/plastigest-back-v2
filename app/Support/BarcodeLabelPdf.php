<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * PDF de etiquetas con código de barras (Code 128) para impresora térmica
 * de 58 mm. Lo usan las etiquetas de productos y las de paquetes.
 */
class BarcodeLabelPdf
{
    /**
     * @param  string  $code  Valor del código de barras (también se imprime como texto).
     */
    public static function stream(
        string $title,
        string $code,
        int $quantity,
        string $fileName,
        ?string $subtitle = null,
        float|string|null $price = null,
    ) {
        $generator = new BarcodeGeneratorPNG();
        $barcode = base64_encode($generator->getBarcode($code, $generator::TYPE_CODE_128));

        $pdf = Pdf::loadView('pdf.labels', compact('title', 'subtitle', 'code', 'barcode', 'price', 'quantity'));

        // 58 mm ≈ 165 puntos de ancho.
        $pdf->setPaper([0, 0, 165, 500], 'portrait');

        return $pdf->stream($fileName);
    }
}
