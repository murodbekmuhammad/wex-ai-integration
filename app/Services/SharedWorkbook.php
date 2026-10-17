<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @class SharedWorkbook
 *
 * @package App\Services
 *
 * Every agent in config/agents.php writes its report into one shared Google
 * Sheet, each into its own tabs: the invoice aging tabs first, then the
 * reserve account tabs, in the order of config/agents.php. A run replaces
 * only its own agent's tabs and carries every other tab over from the
 * sheet's previous version, including tabs the team added themselves, which
 * go last.
 */
class SharedWorkbook
{
    /**
     * tabs
     *
     * The tabs an agent writes, in its order, as its workbook class
     * (config/agents.php "workbook") lists them.
     *
     * @param string $agentKey
     * @return array<int, string>
     */
    public function tabs(string $agentKey): array
    {
        $workbook = config("agents.{$agentKey}.workbook");

        return $workbook ? $workbook::tabs() : [];
    }

    /**
     * merge
     *
     * The shared workbook's .xlsx bytes: the agent's newly built tabs in
     * place of its old ones, next to the other tabs of the previous version.
     *
     * @param string $agentKey the agent that built the workbook
     * @param string $built the agent's workbook .xlsx bytes, with only its own tabs
     * @param string|null $previous the shared sheet's current .xlsx bytes; null or empty when there is none
     * @return string
     */
    public function merge(string $agentKey, string $built, ?string $previous): string
    {
        if (! $previous) {
            return $built;
        }

        $book = $this->load($built);
        $old = $this->load($previous);
        $own = $book->getSheetNames();
        $replaced = [...$own, ...$this->tabs($agentKey)];

        $carried = array_filter($old->getAllSheets(), fn (Worksheet $sheet) => ! in_array($sheet->getTitle(), $replaced, true));

        foreach ($carried as $sheet) {
            $book->addExternalSheet($sheet);
        }

        $order = [];

        foreach (array_keys(config('agents')) as $key) {
            array_push($order, ...($key === $agentKey ? $own : $this->tabs($key)));
        }

        $titles = $book->getSheetNames();
        $known = array_values(array_intersect($order, $titles));

        foreach ([...$known, ...array_diff($titles, $known)] as $index => $title) {
            $book->setIndexByName($title, $index);
        }

        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->setIncludeCharts(true)->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * load
     *
     * Read a workbook with its charts, so the dashboard's charts survive.
     *
     * @param string $xlsx workbook bytes
     * @return Spreadsheet
     */
    private function load(string $xlsx): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        file_put_contents($path, $xlsx);

        try {
            /** @var XlsxReader $reader */
            $reader = IOFactory::createReader('Xlsx');

            return $reader->setIncludeCharts(true)->load($path);
        } finally {
            unlink($path);
        }
    }
}
