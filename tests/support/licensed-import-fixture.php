<?php
declare(strict_types=1);

/** Call inside a test transaction; no production data or permission changes persist. */
function licensedImportFixture(PDO $db,int $company,string $prefix): int
{
    if(getenv('APP_ENV')!=='testing'||!$db->inTransaction())throw new RuntimeException('A rolled-back testing transaction is required.');
    $db->prepare("UPDATE companies SET active=1,approval_status='approved',subscription_status='active',subscription_expires_at=NULL WHERE company_id=?")->execute([$company]);
    $db->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE active=1 AND available=1 AND release_status='released' ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$company]);
    $db->prepare('INSERT INTO users(username,email,password_hash,display_name,must_change_password) VALUES(?,?,?,?,0)')->execute([$prefix,$prefix.'@example.test','test-only-disabled',$prefix]);
    $actor=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO company_users(company_id,user_id,active) VALUES(?,?,1)')->execute([$company,$actor]);
    $db->prepare("INSERT INTO company_user_roles(company_id,user_id,role_id) SELECT ?,?,role_id FROM roles WHERE code='company_owner'")->execute([$company,$actor]);
    $_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
    return $actor;
}
