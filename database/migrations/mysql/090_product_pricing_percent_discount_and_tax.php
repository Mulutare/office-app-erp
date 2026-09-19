<?php

declare(strict_types=1);

return [
    'version' => '090',
    'description' => 'Store approved per-product discount and tax percentages',
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_product_price_changes
 ADD COLUMN approved_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER approved_discount_per_unit,
 ADD COLUMN approved_tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER approved_discount_percent,
 ADD CONSTRAINT ck_sales_price_change_percentages CHECK(approved_discount_percent>=0 AND approved_discount_percent<100 AND approved_tax_percent>=0 AND approved_tax_percent<=100)
SQL,
        <<<'SQL'
UPDATE sales_product_price_changes
SET approved_discount_percent=LEAST(99.99,ROUND(approved_discount_per_unit/proposed_price*100,2))
WHERE proposed_price>0 AND approved_discount_per_unit>0
SQL,
    ],
];
