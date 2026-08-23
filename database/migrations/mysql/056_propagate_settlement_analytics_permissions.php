<?php

declare(strict_types=1);

return [
    'version' => '056',
    'description' =>
        'Propagate Settlement and Analytics permission templates to existing companies',
    'statements' => [
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions
    (
        company_id,
        role_id,
        permission_id,
        granted_by
    )
SELECT
    companies.company_id,
    templates.role_id,
    templates.permission_id,
    companies.owner_user_id
FROM companies
INNER JOIN role_permissions templates
    ON TRUE
INNER JOIN permissions
    ON permissions.permission_id = templates.permission_id
WHERE permissions.code IN (
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
    'analytics.configure'
)
  AND permissions.active = TRUE
SQL,
    ],
];
