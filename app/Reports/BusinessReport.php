<?php

namespace App\Reports;

/**
 * Un reporte del dueño: los mismos datos salen en PDF (vista Blade) y en
 * Excel (hojas), para que los dos digan exactamente lo mismo.
 */
interface BusinessReport
{
    /** Título del documento ("Reporte de ventas"). */
    public function title(): string;

    /** Nombre del archivo, sin extensión. */
    public function fileName(): string;

    /** Vista Blade del PDF. */
    public function view(): string;

    /** Orientación de la hoja carta: 'portrait' o 'landscape'. */
    public function paper(): string;

    /** Datos para la vista. */
    public function data(): array;

    /** Hojas del libro de Excel (ver Spreadsheet). */
    public function sheets(): array;
}
