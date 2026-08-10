<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

final class ImportValidator
{
    /** @param list<list<mixed>> $rows @param array<int,string|null> $mapping @return array{rows:list<array<string,mixed>>,result:ImportResult} */
    public function validate(ExchangeSchema $schema, array $rows, array $mapping): array
    {
        $result = new ImportResult(rowsRead: count($rows));
        $validRows = [];
        $fields = $schema->fieldMap();
        foreach ($rows as $offset => $source) {
            $rowNumber = $offset + 2;
            $row = [];
            foreach ($mapping as $column => $key) {
                if ($key !== null && isset($fields[$key])) {
                    $row[$key] = is_string($source[$column] ?? null) ? trim($source[$column]) : ($source[$column] ?? null);
                }
            }
            $before = count($result->errors);
            foreach ($fields as $key => $field) {
                $value = $row[$key] ?? null;
                if ($field->required && ($value === null || $value === '')) {
                    $result->addError($rowNumber, $field->label, 'Required value is missing.');
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                if ($field->type === 'decimal' && !is_numeric($value)) {
                    $result->addError($rowNumber, $field->label, 'Enter a valid number.');
                } elseif ($field->type === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $result->addError($rowNumber, $field->label, 'Enter a whole number.');
                } elseif ($field->type === 'date' && strtotime((string) $value) === false) {
                    $result->addError($rowNumber, $field->label, 'Enter a valid date.');
                } elseif ($field->type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $result->addError($rowNumber, $field->label, 'Enter a valid email address.');
                }
            }
            if (count($result->errors) === $before) {
                $validRows[] = $row;
                ++$result->valid;
            }
        }
        return ['rows' => $validRows, 'result' => $result];
    }
}
