<?php

declare(strict_types=1);

return static function (\PDO $connection): string {
    $permissionCodes = [
        'sales.settlements.view',
        'sales.settlements.create',
        'sales.settlements.submit',
        'sales.settlements.review',
        'finance.settlements.view',
        'finance.settlements.reconcile',
        'finance.settlements.approve',
        'finance.bank_confirmations.create',
        'finance.bank_accounts.manage',
        'commercial_documents.download',
        'company.document_branding.manage',
        'analytics.view',
        'analytics.configure',
    ];
    $permissionList = implode(
        ',',
        array_map(
            static fn (string $code): string => $connection->quote($code),
            $permissionCodes
        )
    );
    $templateScope =
        ' FROM companies
          INNER JOIN role_permissions templates ON TRUE
          INNER JOIN permissions
             ON permissions.permission_id = templates.permission_id';
    $permissionPredicate =
        ' WHERE permissions.code IN (' . $permissionList . ')
            AND permissions.active = TRUE';
    $completed = array_map(
        'intval',
        $connection->query(
            "SELECT statement_number
             FROM schema_migration_steps
             WHERE version = '056'
             ORDER BY statement_number"
        )->fetchAll(\PDO::FETCH_COLUMN)
    );

    if ($completed !== [] && $completed !== [1]) {
        throw new \RuntimeException(
            'Migration 056 recovery steps are not a valid completed prefix.'
        );
    }

    $expected = (int) $connection->query(
        'SELECT COUNT(*)'
        . $templateScope
        . $permissionPredicate
    )->fetchColumn();
    $actual = (int) $connection->query(
        'SELECT COUNT(*)'
        . $templateScope
        . ' INNER JOIN company_role_permissions grants
                ON grants.company_id = companies.company_id
               AND grants.role_id = templates.role_id
               AND grants.permission_id = templates.permission_id'
        . $permissionPredicate
    )->fetchColumn();

    if ($completed === [1] && $actual !== $expected) {
        throw new \RuntimeException(
            'Migration 056 recovery metadata does not match the database state.'
        );
    }

    return $completed === [] && $actual === $expected
        ? 'baseline'
        : 'apply';
};
