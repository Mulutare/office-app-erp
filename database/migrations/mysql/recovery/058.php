<?php

declare(strict_types=1);

return static function (\PDO $connection): string {
    $completed=array_map('intval',$connection->query("SELECT statement_number FROM schema_migration_steps WHERE version='058' ORDER BY statement_number")->fetchAll(\PDO::FETCH_COLUMN));
    if($completed!==[]&&$completed!==range(1,max($completed)))throw new \RuntimeException('Migration 058 recovery steps are not a valid completed prefix.');
    $tables=(int)$connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('finance_fiscal_years','finance_accounting_period_history')")->fetchColumn();
    $column=(int)$connection->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_accounting_periods' AND column_name='fiscal_year_id'")->fetchColumn();
    $permissions=(int)$connection->query("SELECT COUNT(*) FROM permissions WHERE code IN('finance.period.view','finance.period.manage','finance.period.close','finance.period.reopen')")->fetchColumn();
    $complete=$tables===2&&$column===1&&$permissions===4;
    if($completed!==[]&&!$complete&&max($completed)>=6)throw new \RuntimeException('Migration 058 recovery metadata does not match the database state.');
    return $completed===[]&&$complete?'baseline':'apply';
};
