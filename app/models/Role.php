<?php

declare(strict_types=1);

namespace App\Models;

final class Role
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeRoles(): array
    {
        $statement = \db()->query(
            'SELECT
                role_id,
                code,
                name,
                description
             FROM roles
             WHERE active = TRUE
             ORDER BY name'
        );

        $roles = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($roles)
            ? $roles
            : [];
    }

    /**
     * Return only valid active role IDs.
     *
     * @param list<int> $roleIds
     *
     * @return list<int>
     */
    public function validActiveRoleIds(
        array $roleIds
    ): array {
        $roleIds = array_values(
            array_unique(
                array_filter(
                    $roleIds,
                    static fn (int $roleId): bool =>
                        $roleId > 0
                )
            )
        );

        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($roleIds), '?')
        );

        $statement = \db()->prepare(
            'SELECT role_id
             FROM roles
             WHERE active = TRUE
               AND role_id IN (' . $placeholders . ')'
        );

        foreach ($roleIds as $index => $roleId) {
            $statement->bindValue(
                $index + 1,
                $roleId,
                \PDO::PARAM_INT
            );
        }

        $statement->execute();

        return array_map(
            'intval',
            $statement->fetchAll(
                \PDO::FETCH_COLUMN
            )
        );
    }
}