<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

final class ImportResult
{
    /** @param list<array{row:int,field:string,message:string}> $errors */
    public function __construct(
        public int $rowsRead = 0,
        public int $valid = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $errors = [],
    ) {
    }

    public function addError(int $row, string $field, string $message): void
    {
        $this->errors[] = compact('row', 'field', 'message');
        ++$this->failed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
