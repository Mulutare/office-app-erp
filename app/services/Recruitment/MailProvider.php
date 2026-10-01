<?php
declare(strict_types=1);
namespace App\Services\Recruitment;
interface MailProvider
{
    public function connect(array $mailbox): void;
    /** @return array{validity:int,uids:array} */
    public function inventory(string $since): array;
    public function raw(int $uid): string;
    public function arrival(int $uid): string;
    /** Parse preserved RFC822 bytes; never perform a second mailbox fetch. */
    public function parse(string $raw): array;
    public function close(): void;
}
