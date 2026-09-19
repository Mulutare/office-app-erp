<?php

declare(strict_types=1);

return [
    'version' => '091',
    'description' => 'Record previous SKU discount and tax percentages for direct pricing updates and retire pricing approval permission',
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_product_price_changes
 ADD COLUMN old_discount_percent DECIMAL(5,2) NULL AFTER old_price,
 ADD COLUMN old_tax_percent DECIMAL(5,2) NULL AFTER old_discount_percent
SQL,
        <<<'SQL'
UPDATE permissions SET active=FALSE WHERE code='sales.pricing.approve'
SQL,
    ],
];
