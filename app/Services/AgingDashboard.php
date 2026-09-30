<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @class AgingDashboard
 *
 * @package App\Services
 *
 * Lays out the DASH BOARD tab like the team's factoring sheet: a "Period
 * range" side with KPI tiles and a collected/not collected donut, a "Current
 * position" side with the aging analysis table and chart, and a month by
 * month table and chart. Figures are formulas over the AGING tab, so they
 * follow the team's edits there. Tiles whose source isn't known yet are
 * shown empty.
 */
class AgingDashboard
{
    public const TITLE = 'DASH BOARD';

    /**
     * The dashboard's aging ranges as [label, first day, last day].
     */
    public const RANGES = [['1 - 30', 0, 30], ['31 - 45', 31, 45], ['46 - 60', 46, 60], ['61 - 90', 61, 90], ['91 +', 91, 100000]];

    private const TEAL = '0B7596';

    private const LIGHT = 'CFE2F3';

    private const PALE = '9FC5E8';

    private const BLUE = '3D85C6';

    private const MONEY = '"$"#,##0';

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * build
     *
     * Fill the sheet with the dashboard.
     *
     * @param Worksheet $sheet
     * @param array{as_of: string|null, invoices: array<int, array<string, mixed>>} $report
     * @return void
     */
    public function build(Worksheet $sheet, array $report): void
    {
        $dates = collect($report['invoices'])->pluck('purchase_date')->sort()->values();
        $year = (int) Carbon::parse($report['as_of'] ?? now())->format('Y');

        $sheet->setTitle(self::TITLE);
        $sheet->setShowGridlines(false);
        $sheet->getColumnDimension('A')->setWidth(2);

        foreach (range(2, 26) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(12);
        }

        $sheet->getColumnDimension('AB')->setWidth(18);
        $sheet->getColumnDimension('AC')->setWidth(14);

        $this->banner($sheet, 'B1:Z1', self::TITLE, 18);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $this->section($sheet, 'B3:O3', 'Period range');
        $this->section($sheet, 'Q3:Z3', 'Current position');

        $this->period($sheet, $dates->first(), $dates->last());

        $this->tile($sheet, 'D', 5, 3, 'Total Factored', 'SUM('.$this->col('F').')', 'COUNT('.$this->col('F').')', true);
        $this->tile($sheet, 'G', 5, 3, 'Collected', null, null, true);
        $this->tile($sheet, 'J', 5, 3, 'Broker paid', 'SUM('.$this->col('H').')', 'COUNTIFS('.$this->col('H').',">0",'.$this->col('H').',"<1000000000")', true);
        $this->tile($sheet, 'M', 5, 3, 'Total Received', null, null, true);
        $this->tile($sheet, 'Q', 5, 3, 'Following up', null, null, true);

        [$shortAmount, $shortCount] = $this->byStatus('Short paid');
        [$overAmount, $overCount] = $this->byStatus('Over paid');

        $this->tile($sheet, 'B', 9, 2, 'Short Paid', $shortAmount, $shortCount);
        $this->tile($sheet, 'B', 12, 2, 'Over Paid', $overAmount, $overCount);
        $this->tile($sheet, 'B', 15, 2, 'C.B. Collected', null, null);
        $this->tile($sheet, 'B', 18, 2, 'Claim Solved', null, null);

        $this->tile($sheet, 'Q', 9, 2, 'Short Paid', $shortAmount, $shortCount);
        $this->tile($sheet, 'S', 9, 2, 'Over Paid', $overAmount, $overCount);
        $this->tile($sheet, 'Q', 12, 2, 'Charge Back', null, null);
        $this->tile($sheet, 'S', 12, 2, 'C.B. collected', null, null);
        $this->tile($sheet, 'Q', 15, 2, 'Claim by us', null, null);
        $this->tile($sheet, 'S', 15, 2, 'Claim solved', null, null);
        $this->tile($sheet, 'Q', 18, 2, 'Claim by broker', null, null);

        $this->agingAnalysis($sheet);
        $this->months($sheet, $year);
        $this->chartData($sheet);

        $sheet->addChart($this->donut());
        $sheet->addChart($this->agingChart());
        $sheet->addChart($this->monthChart());
    }

    /**
     * period
     *
     * The "Report period" box with the first and last invoice dates.
     *
     * @param Worksheet $sheet
     * @param string|null $start Y-m-d
     * @param string|null $end Y-m-d
     * @return void
     */
    private function period(Worksheet $sheet, ?string $start, ?string $end): void
    {
        $sheet->mergeCells('B5:C5');
        $this->set($sheet, 'B5', 'Report period');
        $this->set($sheet, 'B6', 'Start');
        $this->set($sheet, 'B7', 'End');
        $this->set($sheet, 'C6', $start ? (float) Date::PHPToExcel(Carbon::parse($start)) : null);
        $this->set($sheet, 'C7', $end ? (float) Date::PHPToExcel(Carbon::parse($end)) : null);

        $style = $sheet->getStyle('B5:C7');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::LIGHT);
        $style->getFont()->getColor()->setRGB(self::TEAL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B5')->getFont()->setBold(true);
        $sheet->getStyle('C6:C7')->getNumberFormat()->setFormatCode('m/d/yyyy');
    }

    /**
     * tile
     *
     * A KPI tile: a teal header and, below it, the amount and how many
     * invoices it covers. A big tile's values span two rows.
     *
     * @param Worksheet $sheet
     * @param string $column first column
     * @param int $row header row
     * @param int $width columns the tile spans, the last one holding the count
     * @param string $label
     * @param string|null $amount formula without "=", or null to leave it empty
     * @param string|null $count formula without "=", or null to leave it empty
     * @param bool $big
     * @return void
     */
    private function tile(Worksheet $sheet, string $column, int $row, int $width, string $label, ?string $amount, ?string $count, bool $big = false): void
    {
        $first = Coordinate::columnIndexFromString($column);
        $last = Coordinate::stringFromColumnIndex($first + $width - 1);
        $beforeLast = Coordinate::stringFromColumnIndex($first + $width - 2);
        $valueEnd = $big ? $row + 2 : $row + 1;

        $this->banner($sheet, "{$column}{$row}:{$last}{$row}", $label, $big ? 13 : 11);

        $this->merge($sheet, "{$column}".($row + 1).":{$beforeLast}{$valueEnd}");
        $this->merge($sheet, "{$last}".($row + 1).":{$last}{$valueEnd}");
        $this->formula($sheet, "{$column}".($row + 1), $amount);
        $this->formula($sheet, "{$last}".($row + 1), $count);

        $amountStyle = $sheet->getStyle("{$column}".($row + 1));
        $amountStyle->getFont()->setBold(true)->setSize($big ? 18 : 12)->getColor()->setRGB(self::TEAL);
        $amountStyle->getNumberFormat()->setFormatCode(self::MONEY);
        $amountStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $countStyle = $sheet->getStyle("{$last}".($row + 1));
        $countStyle->getFont()->setSize($big ? 12 : 10)->getColor()->setRGB(self::TEAL);
        $countStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("{$column}{$row}:{$last}{$valueEnd}")->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
    }

    /**
     * agingAnalysis
     *
     * The "Aging Analysis" table: loads and open amount per aging range, their
     * share of the total, and how many of them the team has emailed or called.
     *
     * @param Worksheet $sheet
     * @return void
     */
    private function agingAnalysis(Worksheet $sheet): void
    {
        $this->banner($sheet, 'U4:Z4', 'Aging Analysis', 13);
        $this->header($sheet, 'U5', ['Range', 'Loads', 'Invoice Amount', '%', 'Emailed', 'Called']);

        foreach (self::RANGES as $index => [$label, $from, $to]) {
            $row = 6 + $index;
            // The invoice amount check keeps empty rows (age 0) out, and the upper bound keeps the header row out.
            $age = $this->col('F').',">0",'.$this->col('E').',">='.$from.'",'.$this->col('E').',"<='.$to.'"';

            $this->set($sheet, "U{$row}", $label);
            $this->formula($sheet, "V{$row}", "COUNTIFS({$age})");
            $this->formula($sheet, "W{$row}", 'SUMIFS('.$this->col('I').",{$age})");
            $this->formula($sheet, "X{$row}", "IF(\$W\$11=0,0,W{$row}/\$W\$11)");
            $this->formula($sheet, "Y{$row}", "COUNTIFS({$age},".$this->col('Q').',"<>")');
            $this->formula($sheet, "Z{$row}", "COUNTIFS({$age},".$this->col('S').',"<>")');
        }

        $this->set($sheet, 'U11', 'TOTAL');

        foreach (['V', 'W', 'X', 'Y', 'Z'] as $column) {
            $this->formula($sheet, "{$column}11", "SUM({$column}6:{$column}10)");
        }

        $sheet->getStyle('W6:W11')->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle('X6:X11')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle('X6:X10')->getFont()->getColor()->setRGB(self::TEAL);
        $sheet->getStyle('U11:Z11')->getFont()->setBold(true);
        $sheet->getStyle('U6:Z11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('U4:Z11')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LIGHT);
        $sheet->getStyle('U4:Z11')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
    }

    /**
     * months
     *
     * The month by month table: invoices submitted (by invoice date),
     * collected and paid by brokers (by payment date), each as an amount and
     * a count. Collected has no source yet. Months are matched as date
     * ranges, since text like "July 2026" is read as a date in criteria.
     *
     * @param Worksheet $sheet
     * @param int $year
     * @return void
     */
    private function months(Worksheet $sheet, int $year): void
    {
        foreach ([['B', 'C', 'Month'], ['D', 'F', 'Submitted'], ['G', 'I', 'Collected'], ['J', 'L', 'Broker Paid']] as [$from, $to, $label]) {
            $this->banner($sheet, "{$from}29:{$to}29", $label, 11);
        }

        foreach (self::MONTHS as $index => $month) {
            $row = 30 + $index;
            $number = $index + 1;
            $range = fn (string $column) => $this->col($column).',">="&DATE('.$year.','.$number.',1),'.$this->col($column).',"<"&DATE('.$year.','.($number + 1).',1)';

            $sheet->mergeCells("B{$row}:C{$row}");
            $sheet->mergeCells("D{$row}:E{$row}");
            $sheet->mergeCells("G{$row}:H{$row}");
            $sheet->mergeCells("J{$row}:K{$row}");
            $this->set($sheet, "B{$row}", $month);
            $this->formula($sheet, "D{$row}", 'SUMIFS('.$this->col('F').','.$range('D').')');
            $this->formula($sheet, "F{$row}", 'COUNTIFS('.$range('D').')');
            $this->formula($sheet, "J{$row}", 'SUMIFS('.$this->col('H').','.$range('K').')');
            $this->formula($sheet, "L{$row}", 'COUNTIFS('.$range('K').','.$this->col('H').',">0")');
        }

        foreach (['B42:C42', 'D42:E42', 'G42:H42', 'J42:K42'] as $range) {
            $sheet->mergeCells($range);
        }

        $this->set($sheet, 'B42', 'Total');

        foreach (['D', 'F', 'J', 'L'] as $column) {
            $this->formula($sheet, "{$column}42", "SUM({$column}30:{$column}41)");
        }

        $sheet->getStyle('D30:D42')->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle('G30:G42')->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle('J30:J42')->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle('B30:L42')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (['F', 'I', 'L'] as $column) {
            $sheet->getStyle("{$column}30:{$column}42")->getFont()->getColor()->setRGB(self::TEAL);
        }

        $total = $sheet->getStyle('B42:L42');
        $total->getFont()->setBold(true)->getColor()->setRGB(self::TEAL);
        $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::LIGHT);
        $sheet->getStyle('B29:L42')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LIGHT);
        $sheet->getStyle('B29:L42')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
    }

    /**
     * chartData
     *
     * The two numbers behind the donut, off to the side of the dashboard.
     *
     * @param Worksheet $sheet
     * @return void
     */
    private function chartData(Worksheet $sheet): void
    {
        $this->set($sheet, 'AB4', 'Chart data');
        $this->set($sheet, 'AB5', 'Not collected yet');
        $this->set($sheet, 'AB6', 'Broker paid');
        $this->formula($sheet, 'AC5', 'SUM('.$this->col('I').')');
        $this->formula($sheet, 'AC6', 'SUM('.$this->col('H').')');

        $sheet->getStyle('AB4:AC6')->getFont()->setSize(9)->getColor()->setRGB('808080');
        $sheet->getStyle('AC5:AC6')->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /**
     * donut
     *
     * @return Chart
     */
    private function donut(): Chart
    {
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $this->ref('AC5:AC6'), self::MONEY, 2);
        $values->setFillColor([self::PALE, self::TEAL]);

        $series = new DataSeries(
            DataSeries::TYPE_DONUTCHART,
            null,
            [0],
            [],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('AB5:AB6'), null, 2)],
            [$values],
        );

        $layout = (new Layout)->setShowVal(true)->setShowPercent(true)->setShowCatName(true);

        return $this->place(
            new Chart('collection', null, new Legend(Legend::POSITION_BOTTOM, null, false), new PlotArea($layout, [$series])),
            'E9',
            'N26',
        );
    }

    /**
     * agingChart
     *
     * @return Chart
     */
    private function agingChart(): Chart
    {
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $this->ref('W6:W10'), self::MONEY, 5);
        $values->setFillColor(self::TEAL);

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('W5'), null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('U6:U10'), null, 5)],
            [$values],
            DataSeries::DIRECTION_COL,
        );

        return $this->place(
            new Chart('aging', new Title('Aging Analysis'), null, new PlotArea((new Layout)->setShowVal(true), [$series])),
            'U13',
            'Z28',
        );
    }

    /**
     * monthChart
     *
     * @return Chart
     */
    private function monthChart(): Chart
    {
        $submitted = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $this->ref('D30:D41'), self::MONEY, 12);
        $submitted->setFillColor(self::TEAL);
        $collected = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $this->ref('G30:G41'), self::MONEY, 12);
        $collected->setFillColor(self::BLUE);

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0, 1],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('D29'), null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('G29'), null, 1),
            ],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $this->ref('B30:B41'), null, 12)],
            [$submitted, $collected],
            DataSeries::DIRECTION_COL,
        );

        return $this->place(
            new Chart('months', null, new Legend(Legend::POSITION_TOP, null, false), new PlotArea((new Layout)->setShowVal(true), [$series])),
            'N29',
            'Z45',
        );
    }

    /**
     * byStatus
     *
     * Formulas for the open amount and count of invoices with a pay status.
     *
     * @param string $status e.g. "Short paid"
     * @return array{0: string, 1: string} amount and count formulas
     */
    private function byStatus(string $status): array
    {
        return [
            'ABS(SUMIFS('.$this->col('I').','.$this->col('L').',"'.$status.'"))',
            'COUNTIF('.$this->col('L').',"'.$status.'")',
        ];
    }

    /**
     * col
     *
     * An absolute reference to a whole AGING column, so rows the team adds
     * are counted too. The header row never matches the numeric criteria.
     *
     * @param string $column
     * @return string e.g. AGING!$E:$E
     */
    private function col(string $column): string
    {
        return "AGING!\${$column}:\${$column}";
    }

    /**
     * ref
     *
     * An absolute reference to a range on this sheet, for charts.
     *
     * @param string $range e.g. W6:W10
     * @return string
     */
    private function ref(string $range): string
    {
        return "'".self::TITLE."'!".preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $range);
    }

    /**
     * place
     *
     * @param Chart $chart
     * @param string $topLeft
     * @param string $bottomRight
     * @return Chart
     */
    private function place(Chart $chart, string $topLeft, string $bottomRight): Chart
    {
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        return $chart;
    }

    /**
     * merge
     *
     * Merge a range unless it is a single cell.
     *
     * @param Worksheet $sheet
     * @param string $range
     * @return void
     */
    private function merge(Worksheet $sheet, string $range): void
    {
        [$from, $to] = explode(':', $range);

        if ($from !== $to) {
            $sheet->mergeCells($range);
        }
    }

    /**
     * banner
     *
     * A merged teal band with white bold text.
     *
     * @param Worksheet $sheet
     * @param string $range
     * @param string $text
     * @param int $size
     * @return void
     */
    private function banner(Worksheet $sheet, string $range, string $text, int $size): void
    {
        $sheet->mergeCells($range);
        $this->set($sheet, explode(':', $range)[0], $text);

        $style = $sheet->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TEAL);
        $style->getFont()->setBold(true)->setSize($size)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * section
     *
     * A section title with a teal line under it.
     *
     * @param Worksheet $sheet
     * @param string $range
     * @param string $text
     * @return void
     */
    private function section(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        $this->set($sheet, explode(':', $range)[0], $text);

        $style = $sheet->getStyle($range);
        $style->getFont()->setSize(12)->getColor()->setRGB(self::TEAL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::TEAL);
    }

    /**
     * header
     *
     * A row of bold teal-on-white column headers starting at a cell.
     *
     * @param Worksheet $sheet
     * @param string $start
     * @param array<int, string> $labels
     * @return void
     */
    private function header(Worksheet $sheet, string $start, array $labels): void
    {
        [$column, $row] = Coordinate::coordinateFromString($start);
        $first = Coordinate::columnIndexFromString($column);

        foreach ($labels as $offset => $label) {
            $this->set($sheet, Coordinate::stringFromColumnIndex($first + $offset).$row, $label);
        }

        $last = Coordinate::stringFromColumnIndex($first + count($labels) - 1);
        $style = $sheet->getStyle("{$start}:{$last}{$row}");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TEAL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * set
     *
     * Write a plain value with an explicit type, so text never becomes a formula.
     *
     * @param Worksheet $sheet
     * @param string $cell
     * @param string|int|float|null $value
     * @return void
     */
    private function set(Worksheet $sheet, string $cell, string|int|float|null $value): void
    {
        if ($value === null) {
            return;
        }

        $sheet->setCellValueExplicit($cell, $value, is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
    }

    /**
     * formula
     *
     * @param Worksheet $sheet
     * @param string $cell
     * @param string|null $formula without the leading "="; null leaves the cell empty
     * @return void
     */
    private function formula(Worksheet $sheet, string $cell, ?string $formula): void
    {
        if ($formula !== null) {
            $sheet->setCellValueExplicit($cell, "={$formula}", DataType::TYPE_FORMULA);
        }
    }
}
