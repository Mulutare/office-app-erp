<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test') throw new RuntimeException('Isolated synthetic database required.');
$directory=sys_get_temp_dir().'/recruitment-migration-test';
if(!is_dir($directory)) mkdir($directory,0700);
// Current authorization uses the existing migration-096 per-user override table.
copy(__DIR__.'/../database/migrations/mysql/093_company_role_module_gates.php',$directory.'/093_company_role_module_gates.php');
copy(__DIR__.'/../database/migrations/mysql/096_company_user_permission_overrides.php',$directory.'/096_company_user_permission_overrides.php');
copy(__DIR__.'/../database/migrations/mysql/110_recruitment.php',$directory.'/110_recruitment.php');
$runner=new App\Database\MigrationRunner(db(),'mysql');
$first=$runner->run($directory); $second=$runner->run($directory);
if(!in_array('110',$second['skipped'],true)) throw new RuntimeException('Migration rerun must be skipped.');
echo "Recruitment migration 110 applied and rerun safely skipped.\n";
