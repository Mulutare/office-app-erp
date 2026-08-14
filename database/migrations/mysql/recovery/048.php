<?php
declare(strict_types=1);
return static function(PDO $c):string{
    $global=(int)$c->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.code='company_owner' AND p.code='administration.modules.manage'")->fetchColumn();
    $company=(int)$c->query("SELECT COUNT(*) FROM company_role_permissions crp JOIN roles r ON r.role_id=crp.role_id JOIN permissions p ON p.permission_id=crp.permission_id WHERE r.code='company_owner' AND p.code='administration.modules.manage'")->fetchColumn();
    $eligible=(int)$c->query("SELECT COUNT(*) FROM companies WHERE owner_user_id IS NOT NULL")->fetchColumn();
    $steps=array_map('intval',$c->query("SELECT statement_number FROM schema_migration_steps WHERE version='048' ORDER BY statement_number")->fetchAll(PDO::FETCH_COLUMN));
    if($steps===[]){if($global===0&&$company===0)return'apply';if($global===1&&$company===$eligible)return'baseline';throw new RuntimeException('Migration 048 found untracked partial grants.');}
    if($steps===[1]&&$global===1&&$company===0)return'apply';
    if($steps===[1,2]&&$global===1&&$company===$eligible)return'apply';
    throw new RuntimeException('Migration 048 recovery state is inconsistent.');
};
