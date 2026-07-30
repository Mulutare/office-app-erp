<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

/**
 * Splits reviewed SQL files without treating delimiters inside quoted text or
 * comments as statement boundaries.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length
                ? $sql[$index + 1]
                : '';

            if ($lineComment) {
                if (
                    $character === "\n"
                    || $character === "\r"
                ) {
                    $lineComment = false;
                    $buffer .= ' ';
                }

                continue;
            }

            if ($blockComment) {
                if (
                    $character === '*'
                    && $next === '/'
                ) {
                    $blockComment = false;
                    $buffer .= ' ';
                    $index++;
                }

                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;

                if (
                    $character === '\\'
                    && $next !== ''
                ) {
                    $buffer .= $next;
                    $index++;

                    continue;
                }

                if ($character !== $quote) {
                    continue;
                }

                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;

                    continue;
                }

                $quote = null;

                continue;
            }

            if (
                $character === '-'
                && $next === '-'
            ) {
                $lineComment = true;
                $index++;

                continue;
            }

            if ($character === '#') {
                $lineComment = true;

                continue;
            }

            if (
                $character === '/'
                && $next === '*'
            ) {
                $blockComment = true;
                $index++;

                continue;
            }

            if (
                $character === '\''
                || $character === '"'
                || $character === '`'
            ) {
                $quote = $character;
                $buffer .= $character;

                continue;
            }

            if ($character === ';') {
                $this->append(
                    $statements,
                    $buffer
                );
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        if (
            $quote !== null
            || $blockComment
        ) {
            throw new RuntimeException(
                'The SQL file contains an unterminated quoted value or comment.'
            );
        }

        $this->append(
            $statements,
            $buffer
        );

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function append(
        array &$statements,
        string $statement
    ): void {
        $statement = trim($statement);

        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
}
