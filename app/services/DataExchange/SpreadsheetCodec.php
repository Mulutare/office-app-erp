<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class SpreadsheetCodec
{
    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    public function read(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $book = $reader->load($path, 0);
        $sheet = $book->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestRow - 1 > FileGuard::MAX_ROWS || $highestColumn > FileGuard::MAX_COLUMNS) {
            throw new RuntimeException('Workbook exceeds the row or column limit.');
        }
        $records = [];
        for ($row = 1; $row <= $highestRow; ++$row) {
            $values = [];
            for ($column = 1; $column <= $highestColumn; ++$column) {
                $cell = $sheet->getCell([$column, $row]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    throw new RuntimeException('Spreadsheet formulas are not accepted.');
                }
                $values[] = $cell->getValue();
            }
            $records[] = $values;
        }
        $book->disconnectWorksheets();
        return ['headers' => array_map('strval', array_shift($records) ?? []), 'rows' => $records];
    }

    /** @param list<string> $headers @param list<array<string, mixed>> $rows */
    public function write(array $headers, array $rows, ?ExchangeSchema $schema = null): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 2], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        if ($schema !== null) {
            $instructions = $book->createSheet();
            $instructions->setTitle('Instructions');
            $instructions->fromArray([['Field', 'Required', 'Type', 'Example']]);
            foreach ($schema->fields as $index => $field) {
                $instructions->fromArray([[ $field->label, $field->required ? 'Yes' : 'No', $field->type, $field->example ?? '' ]], null, 'A' . ($index + 2));
            }
            $instructions->setCellValue('A' . (count($schema->fields) + 3), 'External IDs are company-scoped. Re-importing the same External ID updates the existing record.');
        }
        $stream = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();
        return $contents === false ? '' : $contents;
    }
}
