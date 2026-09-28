<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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
                $value = $cell->getValue();
                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $value = ExcelDate::excelToDateTimeObject((float)$value)->format((float)$value < 1 ? 'H:i' : 'Y-m-d');
                }
                $values[] = $value;
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
        $fieldTypes = [];
        if ($schema !== null) foreach ($schema->fields as $field) $fieldTypes[$field->label] = $field->type;
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $type = $fieldTypes[$headers[$columnIndex] ?? ''] ?? 'string';
                $cell = [$columnIndex + 1, $rowIndex + 2];
                if (in_array($type, ['decimal','integer'], true) && is_numeric($value)) {
                    $sheet->setCellValueExplicit($cell, $type === 'integer' ? (int)$value : (float)$value, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type === 'integer' ? '#,##0' : '#,##0.00');
                } elseif (in_array($type,['date','datetime'],true) && is_string($value) && $value!=='' && strtotime($value) !== false) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel(new \DateTimeImmutable($value)));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type==='datetime'?'yyyy-mm-dd hh:mm:ss':'yyyy-mm-dd');
                } else {
                    $text = (string)($value ?? '');
                    if ($text !== '' && in_array($text[0], ['=','+','-','@'], true)) $text = "'".$text;
                    $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
                }
            }
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFDCE7F7');
        foreach (range(1, count($headers)) as $column) $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        if ($schema !== null) {
            $instructions = $book->createSheet();
            $instructions->setTitle('Instructions');
            $instructions->fromArray([['Field', 'Required', 'Type', 'Example']]);
            foreach ($schema->fields as $index => $field) {
                $instructions->fromArray([[ $field->label, $field->required ? 'Yes' : 'No', $field->type, $field->example ?? '' ]], null, 'A' . ($index + 2));
            }
            $instruction=!$schema->canImport?'Export only. Use the application workflow to create or change these records.'
                :(MasterImportValidator::supportsUpdate($schema->entity)
                    ?'Choose Create new records or Update existing records explicitly. Update requires a company-scoped External ID. Any invalid row prevents all writes. Quotations must remain drafts; approved pricing controls their amounts.'
                    :'Create-only import in the active company. Duplicates are rejected; any invalid row prevents all writes. Dates: YYYY-MM-DD. Times: HH:MM.');
            $instructions->setCellValue('A'.(count($schema->fields)+3),$instruction);

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
