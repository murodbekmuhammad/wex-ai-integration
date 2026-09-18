<?php

namespace App\Services;

use App\Models\ReportTable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class TableExporter
 *
 * @package App\Services
 *
 * Turns a table Claude built into an Excel or PDF download.
 */
class TableExporter
{
    /**
     * xlsx
     *
     * The table as an Excel download.
     *
     * @param ReportTable $table
     * @return StreamedResponse
     */
    public function xlsx(ReportTable $table): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet($table);

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $table->downloadName('xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * xlsxContents
     *
     * The bytes of the table's Excel workbook, e.g. for uploading elsewhere.
     *
     * @param ReportTable $table
     * @return string
     */
    public function xlsxContents(ReportTable $table): string
    {
        ob_start();
        (new Xlsx($this->spreadsheet($table)))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * pdf
     *
     * A printable PDF of the table, landscape when it has many columns.
     *
     * @param ReportTable $table
     * @return Response
     */
    public function pdf(ReportTable $table): Response
    {
        return Pdf::loadView('tables.pdf', ['table' => $table, 'sources' => $this->sources($table)])
            ->setPaper('a4', count($table->columns) > 5 ? 'landscape' : 'portrait')
            ->download($table->downloadName('pdf'));
    }

    /**
     * spreadsheet
     *
     * A workbook with the table on the first sheet and what it was built
     * from on an "About" sheet.
     *
     * @param ReportTable $table
     * @return Spreadsheet
     */
    private function spreadsheet(ReportTable $table): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $data = $spreadsheet->getActiveSheet();
        $data->setTitle($this->sheetTitle($table->title));
        $this->fill($data, [$table->columns, ...$table->rows]);

        $lastColumn = Coordinate::stringFromColumnIndex(count($table->columns));
        $data->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $data->freezePane('A2');
        $data->setAutoFilter("A1:{$lastColumn}".(count($table->rows) + 1));

        $about = $spreadsheet->createSheet();
        $about->setTitle('About');
        $this->fill($about, [
            ['Title', $table->title],
            ['Request', $table->request],
            ['Summary', $table->summary],
            ['Built from', $this->sources($table)],
            ['Warnings', implode("\n", $table->warnings)],
            ['Created', $table->created_at?->toDayDateTimeString().' UTC'],
        ]);
        $about->getStyle('A1:A6')->getFont()->setBold(true);
        $about->getColumnDimension('B')->setWidth(100);
        $about->getStyle('B1:B6')->getAlignment()->setWrapText(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * fill
     *
     * Write rows into a sheet, starting at A1, and size the columns to fit.
     * Every value is written with an explicit type, so text from a PDF that
     * starts with "=" stays text instead of becoming a formula.
     *
     * @param Worksheet $sheet
     * @param array<int, array<int, string|int|float|null>> $rows
     * @return void
     */
    private function fill(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1),
                    $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                );
            }
        }

        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }

    /**
     * sources
     *
     * The file names of the PDFs the table was built from.
     *
     * @param ReportTable $table
     * @return string
     */
    private function sources(ReportTable $table): string
    {
        return $table->documents()->pluck('filename')->implode(', ') ?: '—';
    }

    /**
     * sheetTitle
     *
     * Excel sheet names are at most 31 characters and can't contain \ / ? * [ ] :
     *
     * @param string $title
     * @return string
     */
    private function sheetTitle(string $title): string
    {
        $title = trim(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $title), 0, 31));

        return $title === '' || strcasecmp($title, 'About') === 0 ? 'Table' : $title;
    }
}
