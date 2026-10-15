<?php

namespace App\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Libro de Excel (.xlsx) a partir de hojas descritas como datos, para que
 * cada reporte diga qué columnas tiene y no cómo se escribe un archivo.
 *
 * Cada hoja: ['name', 'title' => [renglones de encabezado], 'columns' =>
 * [['label', 'type', 'width']], 'rows' => [...], 'totals' => [...]].
 * Un renglón es una lista de valores, o Spreadsheet::row($valores, $tono)
 * para resaltarlo (agotado, faltante…).
 *
 * Los números se escriben como números con formato, no como texto: el
 * contador tiene que poder sumar y filtrar.
 */
final class Spreadsheet
{
    public const TEXT = 'text';
    public const MONEY = 'money';
    public const QTY = 'qty';
    public const INT = 'int';
    /** Fracción (0.25) que Excel muestra como 25.0 %. */
    public const PCT = 'pct';
    /** Fecha u hora como fecha de Excel (se puede ordenar y filtrar), no como texto. */
    public const DATE = 'date';
    public const DATETIME = 'datetime';

    private const FORMATS = [
        self::MONEY => '"$"#,##0.00;-"$"#,##0.00',
        self::QTY => '#,##0.###',
        self::INT => '#,##0',
        self::PCT => '0.0%',
        self::DATE => 'dd/mm/yyyy',
        self::DATETIME => 'dd/mm/yyyy hh:mm',
    ];

    private const TONES = [
        'danger' => 'F6DCD7',
        'warning' => 'FBEFD5',
        'muted' => 'EEEEEE',
    ];

    /** @var array<string, Style> */
    private array $styles = [];

    public static function row(array $values, ?string $tone = null): array
    {
        return ['__values' => $values, '__tone' => $tone];
    }

    /** Valor con un formato distinto al de su columna (un conteo en una columna de importes). */
    public static function cell(mixed $value, string $type): array
    {
        return ['__value' => $value, '__type' => $type];
    }

    /** @param array<int, array> $sheets */
    public static function download(string $fileName, array $sheets): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new self())->write($path, $sheets);

        return response()->download($path, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @param array<int, array> $sheets */
    public function write(string $path, array $sheets): void
    {
        $writer = new Writer();
        $writer->openToFile($path);

        foreach (array_values($sheets) as $i => $spec) {
            $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName($spec['name'] ?? 'Hoja ' . ($i + 1)));

            $columns = $spec['columns'];
            foreach ($columns as $c => $column) {
                $sheet->setColumnWidth((float) ($column['width'] ?? 14), $c + 1);
            }

            $rowNumber = 0;
            foreach ($spec['title'] ?? [] as $t => $line) {
                $writer->addRow(Row::fromValues([$line], $this->style($t === 0 ? 'title' : 'subtitle')));
                $rowNumber++;
            }
            if ($rowNumber > 0) {
                $writer->addRow(Row::fromValues([]));
                $rowNumber++;
            }

            $writer->addRow(Row::fromValues(array_column($columns, 'label'), $this->style('header')));
            $headerRow = ++$rowNumber;

            foreach ($spec['rows'] ?? [] as $row) {
                $writer->addRow($this->dataRow($row, $columns));
                $rowNumber++;
            }
            $lastDataRow = $rowNumber;

            if (!empty($spec['totals'])) {
                $writer->addRow($this->dataRow($spec['totals'], $columns, 'total'));
                $rowNumber++;
            }

            $sheet->setSheetView((new SheetView())->setFreezeRow($headerRow + 1));
            if ($lastDataRow > $headerRow) {
                $sheet->setAutoFilter(new AutoFilter(0, $headerRow, count($columns) - 1, $lastDataRow));
            }
        }

        $writer->close();
    }

    private function dataRow(array $row, array $columns, ?string $forcedTone = null): Row
    {
        $tone = $forcedTone;
        if (array_key_exists('__values', $row)) {
            $tone ??= $row['__tone'];
            $row = $row['__values'];
        }

        $cells = [];
        foreach (array_values($columns) as $c => $column) {
            $value = $row[$c] ?? null;
            $type = $column['type'] ?? self::TEXT;
            if (is_array($value) && array_key_exists('__value', $value)) {
                $type = $value['__type'];
                $value = $value['__value'];
            }
            if (in_array($type, [self::DATE, self::DATETIME], true)) {
                // Una etiqueta ("Total") en una columna de fechas se queda como texto.
                if (is_string($value) && strtotime($value) !== false) {
                    $value = new \DateTimeImmutable($value);
                } elseif (!$value instanceof \DateTimeInterface) {
                    $type = self::TEXT;
                }
            } elseif ($value !== null && $type !== self::TEXT && is_numeric($value)) {
                $value = $type === self::INT ? (int) $value : (float) $value;
            }
            $cells[] = Cell::fromValue($value, $this->style($type, $tone));
        }

        return new Row($cells);
    }

    private function style(string $kind, ?string $tone = null): Style
    {
        $key = $kind . '|' . $tone;
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }

        $style = new Style();
        match ($kind) {
            'title' => $style->setFontBold()->setFontSize(14)->setFontColor('4F7A3A'),
            'subtitle' => $style->setFontColor('725C3A'),
            'header' => $style->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('809671')->setShouldWrapText(),
            default => isset(self::FORMATS[$kind]) ? $style->setFormat(self::FORMATS[$kind]) : $style,
        };

        if ($tone === 'total') {
            $style->setFontBold()->setBackgroundColor('E5E0D8');
        } elseif ($tone && isset(self::TONES[$tone])) {
            $style->setBackgroundColor(self::TONES[$tone]);
        }

        return $this->styles[$key] = $style;
    }

    /** Excel no admite más de 31 caracteres ni []:*?/\ en el nombre de una hoja. */
    private function sheetName(string $name): string
    {
        return mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 0, 31);
    }
}
