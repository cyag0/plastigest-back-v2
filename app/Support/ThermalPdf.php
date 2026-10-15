<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * PDF para impresora térmica (rollo de 58 u 80 mm) con el largo justo del
 * contenido: un ticket con la altura fija de una hoja gasta papel en blanco
 * o se corta a la mitad.
 *
 * Se renderiza dos veces: la primera en una hoja muy larga para medir dónde
 * termina el contenido, la segunda con ese largo.
 */
class ThermalPdf
{
    private const PT_PER_MM = 72 / 25.4;

    public static function width(int $mm): int
    {
        return in_array($mm, [58, 80], true) ? $mm : 80;
    }

    public static function response(string $view, array $data, string $fileName, int $widthMm = 80): Response
    {
        $widthMm = self::width($widthMm);
        $data['paperWidthMm'] = $widthMm;
        $widthPt = $widthMm * self::PT_PER_MM;

        // 1) Medir: altura del <body> ya acomodado.
        $height = 0.0;
        $probe = Pdf::loadView($view, $data)->setPaper([0, 0, $widthPt, 5000])
            ->setOption('isFontSubsettingEnabled', true);
        $probe->getDomPDF()->setCallbacks([[
            'event' => 'end_frame',
            'f' => function ($frame) use (&$height) {
                if (strtolower($frame->get_node()->nodeName) === 'body') {
                    $box = $frame->get_padding_box();
                    $height = max($height, $box['y'] + $box['h']);
                }
            },
        ]]);
        $probe->render();

        // 2) Imprimir con ese largo (+ un margen para el corte de la impresora).
        // Sólo los caracteres usados de la fuente: el PDF baja de ~850 KB a
        // unos pocos KB, que importa al bajarlo al celular para imprimir.
        $pdf = Pdf::loadView($view, $data)->setPaper([0, 0, $widthPt, max($height + 24, 200)])
            ->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }
}
