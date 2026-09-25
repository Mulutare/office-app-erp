<?php

declare(strict_types=1);

return [
    'version' => '097',
    'description' => 'Link manager-issued cash float to the company FLOAT product master',
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_dsa_float_issuances
 ADD COLUMN product_id BIGINT UNSIGNED NULL AFTER manager_user_id,
 ADD CONSTRAINT fk_dsa_float_product FOREIGN KEY(company_id,product_id)
 REFERENCES sales_products(company_id,product_id) ON DELETE RESTRICT
SQL,
        // This table already represents cash float exclusively. Associate legacy
        // records only where the company's existing FLOAT definition is known.
        <<<'SQL'
UPDATE sales_dsa_float_issuances f
JOIN sales_products p ON p.company_id=f.company_id AND p.sku='FLOAT' AND p.deleted_at IS NULL
SET f.product_id=p.product_id
WHERE f.product_id IS NULL
SQL,
    ],
];
