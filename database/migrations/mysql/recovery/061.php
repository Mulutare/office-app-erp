<?php
declare(strict_types=1);
return static function(PDO $c):string{
    $steps=array_map('intval',$c->query("SELECT statement_number FROM schema_migration_steps WHERE version='061' ORDER BY statement_number")->fetchAll(PDO::FETCH_COLUMN));
    if($steps!==[]&&$steps!==range(1,max($steps)))throw new RuntimeException('Migration 061 recovery steps are not a valid completed prefix.');
    $table=(int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='authenticated_user_sessions'")->fetchColumn();
    $columns=(int)$c->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='authenticated_user_sessions' AND column_name IN('authenticated_user_session_id','company_id','user_id','session_hash','signed_in_at','last_activity_at','expires_at','revoked_at','ip_address','user_agent','created_at','updated_at')")->fetchColumn();
    $complete=$table===1&&$columns===12;
    if($steps!==[]&&max($steps)>=1&&!$complete)throw new RuntimeException('Migration 061 recovery metadata does not match the database state.');
    return $steps===[]&&$complete?'baseline':'apply';
};
