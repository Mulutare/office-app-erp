<?php

declare(strict_types=1);

namespace App\Services;

final class TemporaryPasswordGenerator
{
    public function generate(int $length = 16): string
    {
        $length = max(12, $length);
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%&*?',
        ];
        $characters = [];

        foreach ($groups as $group) {
            $characters[] = $group[
                random_int(0, strlen($group) - 1)
            ];
        }

        $allCharacters = implode('', $groups);

        while (count($characters) < $length) {
            $characters[] = $allCharacters[
                random_int(
                    0,
                    strlen($allCharacters) - 1
                )
            ];
        }

        for (
            $index = count($characters) - 1;
            $index > 0;
            $index--
        ) {
            $swapIndex = random_int(0, $index);
            [
                $characters[$index],
                $characters[$swapIndex],
            ] = [
                $characters[$swapIndex],
                $characters[$index],
            ];
        }

        return implode('', $characters);
    }
}
