<?php
declare(strict_types=1);
return static function (PDO $connection): string {
    $tables=['company_bank_accounts','company_document_branding','sales_settlements','sales_settlement_lines','bank_confirmations','sales_settlement_events','bank_transactions'];
    $effects=[];
    foreach($tables as $i=>$table){$s=$connection->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');$s->execute(['table'=>$table]);$effects[$i+1]=(int)$s->fetchColumn()===1;}
    $effects[8]=(int)$connection->query("SELECT COUNT(*) FROM permissions WHERE code IN('sales.settlements.view','sales.settlements.create','sales.settlements.submit','sales.settlements.review','finance.settlements.view','finance.settlements.reconcile','finance.settlements.approve','finance.bank_confirmations.create','finance.bank_accounts.manage','commercial_documents.download','company.document_branding.manage')")->fetchColumn()===11;
    $effects[9]=(int)$connection->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.code='sales.settlements.view'")->fetchColumn()>0;
    $steps=$connection->query("SELECT statement_number FROM schema_migration_steps WHERE version='054' ORDER BY statement_number")->fetchAll(PDO::FETCH_COLUMN);
    $completed=array_map('intval',$steps);
    if($completed===[]){if(!in_array(true,$effects,true))return 'apply';if(!in_array(false,$effects,true))return 'baseline';throw new RuntimeException('Migration 054 found an untracked partial settlement schema.');}
    if($completed!==range(1,max($completed)))throw new RuntimeException('Migration 054 recovery steps are not a valid completed prefix.');
    foreach($completed as $number)if(empty($effects[$number]))throw new RuntimeException('Migration 054 recovery metadata does not match the database structure.');
    foreach(array_keys($effects) as $number)if($number>max($completed)&&$effects[$number])throw new RuntimeException('Migration 054 contains an unrecorded out-of-order schema effect.');
    return 'apply';
};
