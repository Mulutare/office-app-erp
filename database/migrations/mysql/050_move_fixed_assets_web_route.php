<?php

declare(strict_types=1);

return [
    'version' => '050',
    'description' => 'Move Fixed Assets browser routes away from the public assets directory',
    'statements' => [
        <<<'SQL'
UPDATE erp_modules
SET route_path = '/assets-management'
WHERE code = 'assets'
  AND route_path <> '/assets-management'
SQL,
    ],
];
