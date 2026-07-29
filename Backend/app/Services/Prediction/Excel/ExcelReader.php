<?php

namespace App\Services\Prediction\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ExcelReader
{
    /**
     * @var array<string, array<int, array{worksheet:string,rows:array}>>
     */
    private array $workbookCache = [];

    public function readWorkbook(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        if (isset($this->workbookCache[$path])) {
            return $this->workbookCache[$path];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $worksheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $worksheets[] = [
                'worksheet' => $worksheet->getTitle(),
                'rows' => $worksheet->toArray(null, true, true, true),
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->workbookCache[$path] = $worksheets;
    }

    public function readFirstWorksheet(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        return $this->readWorkbook($path)[0];
    }
}
