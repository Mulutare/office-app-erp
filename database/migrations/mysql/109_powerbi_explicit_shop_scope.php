<?php

declare(strict_types=1);

// Explicit aliases allow comparison with the server's stored view definition.
$warehouseSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_warehouses AS
SELECT w.company_id AS company_id,w.warehouse_id AS warehouse_id,x.external_id AS pbi_shop_id,
       w.code AS shop_code,w.name AS shop_name,w.warehouse_type AS warehouse_type,
       w.branch_id AS branch_id,w.address AS address,w.phone AS phone,w.email AS email,
       w.manager_user_id AS manager_user_id,w.active AS active,w.created_at AS created_at,
       w.updated_at AS updated_at,'ERP_LIVE' AS SourceSystem
FROM inventory_warehouses w
LEFT JOIN data_external_ids x
  ON x.company_id=w.company_id AND x.entity_type='warehouses' AND x.entity_id=w.warehouse_id
 AND x.external_id REGEXP '^PBI-SHOP-[0-9]{3}$'
WHERE w.company_id=2 AND w.deleted_at IS NULL
SQL;
$auditSql = <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_109_explicit_shop_scope_audit AS
SELECT 2 AS company_id,
       (SELECT COUNT(*) FROM vw_powerbi_warehouses w WHERE w.company_id=2 AND w.pbi_shop_id IS NOT NULL) AS explicit_pbi_shop_count,
       (SELECT COUNT(*) FROM vw_powerbi_warehouses w WHERE w.company_id=2 AND w.pbi_shop_id IS NOT NULL AND w.pbi_shop_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$') AS unexpected_scoped_external_ids,
       (SELECT COUNT(*) FROM data_external_ids x
        INNER JOIN inventory_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id
        WHERE w.company_id=2 AND w.deleted_at IS NULL AND x.entity_type='warehouses'
          AND x.external_id NOT REGEXP '^PBI-SHOP-[0-9]{3}$') AS generic_warehouse_external_ids_ignored,
       (SELECT COUNT(*) FROM vw_powerbi_current_shop_manager_scope s WHERE s.company_id=2) AS current_shop_scope_rows
SQL;

return [
    'version' => '109',
    'description' => 'Restrict Power BI shop scope to explicit PBI shop external IDs without changing warehouse or reporting data',
    'preflight' => static function (\PDO $connection) use ($warehouseSql, $auditSql): string {
        // Require every object introduced by the governed 100-108 layer, including
        // each sentinel. No earlier migration is altered or re-executed here.
        $required = ['data_external_ids', 'inventory_warehouses', 'bi_powerbi_reporting_control',
            'vw_powerbi_warehouses', 'vw_powerbi_current_shop_manager_scope'];
        foreach (glob(__DIR__ . '/10[0-8]_*.php') as $file) {
            $definition = require $file;
            foreach ($definition['statements'] as $statement) {
                if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $statement, $match)) {
                    $required[] = $match[1];
                }
            }
        }
        $required = array_values(array_unique($required));
        $quoted = implode(',', array_map($connection->quote(...), $required));
        $count = (int)$connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN($quoted)")->fetchColumn();
        if ($count !== count($required)) {
            throw new \RuntimeException('Migration 109 requires the complete migration-108 Power BI layer.');
        }
        $normalize = static function (string $sql) use ($connection): string {
            $sql = preg_replace('/^CREATE OR REPLACE VIEW \w+ AS\s*/i', '', $sql);
            $database = (string)$connection->query('SELECT DATABASE()')->fetchColumn();
            // Normalize server-added quoting and current-schema qualifications
            // outside literals, preserving the regex and SourceSystem exactly.
            $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => &$part) {
                if ($i % 2 === 0) {
                    $part = str_replace('`', '', $part);
                    $part = str_replace($database . '.', '', $part);
                    // MariaDB canonicalizes these equivalent SQL forms.
                    $part = preg_replace('/COUNT\(\*\)/i', 'COUNT(0)', $part);
                    $part = preg_replace('/\bINNER\s+JOIN\b/i', 'JOIN', $part);
                    $part = preg_replace('/(\w+\.\w+)\s+NOT\s+REGEXP\s*/i', '!$1 REGEXP ', $part);
                    $part = strtolower((string)preg_replace('/[\s()]/', '', $part));
                }
            }
            unset($part);
            return implode('', $parts);
        };
        $readView = static function (string $name) use ($connection): ?string {
            $q = $connection->prepare('SELECT view_definition FROM information_schema.views WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$name]);
            $value = $q->fetchColumn();
            return $value === false ? null : (string)$value;
        };
        $current = $readView('vw_powerbi_warehouses');
        $sentinel = $readView('vw_powerbi_109_explicit_shop_scope_audit');
        $legacySql = str_replace(" AND x.external_id REGEXP '^PBI-SHOP-[0-9]{3}$'", '', $warehouseSql);
        $explicit = $connection->query("SELECT x.external_id FROM data_external_ids x INNER JOIN inventory_warehouses w ON w.company_id=x.company_id AND w.warehouse_id=x.entity_id WHERE w.company_id=2 AND w.deleted_at IS NULL AND x.entity_type='warehouses' AND x.external_id REGEXP '^PBI-SHOP-[0-9]{3}$' ORDER BY x.external_id")->fetchAll(\PDO::FETCH_COLUMN);
        $expected = array_map(static fn(int $id): string => sprintf('PBI-SHOP-%03d', $id), range(1, 22));
        if ($explicit !== $expected) {
            throw new \RuntimeException('Migration 109 requires exactly PBI-SHOP-001 through PBI-SHOP-022; unexpected explicit scope.');
        }
        if ($current !== null && $normalize($current) === $normalize($legacySql) && $sentinel === null) {
            return 'apply';
        }
        if ($current !== null && $normalize($current) === $normalize($warehouseSql)
            && $sentinel !== null && $normalize($sentinel) === $normalize($auditSql)) {
            $audit = $connection->query('SELECT * FROM vw_powerbi_109_explicit_shop_scope_audit')->fetch(\PDO::FETCH_ASSOC);
            if ((int)$audit['explicit_pbi_shop_count'] === 22 && (int)$audit['unexpected_scoped_external_ids'] === 0 && (int)$audit['current_shop_scope_rows'] === 22) {
                return 'baseline';
            }
        }
        throw new \RuntimeException('Migration 109 found a partial or unexpected explicit-shop-scope state.');
    },
    'statements' => [$warehouseSql, $auditSql],
];
