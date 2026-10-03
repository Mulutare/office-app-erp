<?php
require __DIR__.'/../app/helpers/bootstrap.php';
if (getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test') throw new RuntimeException('Disposable fixtures only');
try { $pdo=db();
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Fresh disposable database required; this helper never clears an existing database.'); $splitter=new App\Database\SqlStatementSplitter();
foreach(glob(__DIR__.'/../database/migrations/*.sql') as $f) foreach($splitter->split(file_get_contents($f)) as $sql) $pdo->exec($sql);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$dir=sys_get_temp_dir().'/validation-prefix'; mkdir($dir,0700,true);
foreach(glob(__DIR__.'/../database/migrations/mysql/*.php') as $f) if((int)basename($f)<72) copy($f,$dir.'/'.basename($f));
$runner=new App\Database\MigrationRunner($pdo,'mysql'); $runner->run($dir);
// Synthetic, empty BI dimensions absent from the tracked fresh-install catalog.
// These are test prerequisites, not production migrations or an installer repair.
$pdo->exec('CREATE TABLE bi_clusters(cluster_id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED,code VARCHAR(80),name VARCHAR(190),active BOOLEAN,updated_at DATETIME) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE bi_warehouse_cluster_map(warehouse_id BIGINT UNSIGNED,cluster_id BIGINT UNSIGNED,active BOOLEAN,updated_at DATETIME) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE bi_warehouse_territory_map(warehouse_id BIGINT UNSIGNED,territory_id BIGINT UNSIGNED,active BOOLEAN,updated_at DATETIME) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE bi_employees(employee_id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED,employee_name VARCHAR(190),active BOOLEAN) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE bi_employee_assignments(employee_id BIGINT UNSIGNED,warehouse_id BIGINT UNSIGNED,role_code VARCHAR(80),active BOOLEAN,effective_from DATE,effective_to DATE) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
foreach([__DIR__.'/../database/seeds/*.sql',__DIR__.'/../tests/fixtures/*.sql'] as $pattern) { $files=glob($pattern);sort($files);foreach($files as $f)foreach($splitter->split(file_get_contents($f)) as $sql)$pdo->exec($sql); }
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$suffix=sys_get_temp_dir().'/recruitment-fixture-through-106';mkdir($suffix,0700,true);
foreach(glob(__DIR__.'/../database/migrations/mysql/*.php') as $f) if((int)basename($f)<=106) copy($f,$suffix.'/'.basename($f));
$runner->run($suffix);
$p=db();$pairs=[[2,90],[3,101],[4,36],[6,104],[5,97],[7,106],[8,77],[9,102],[10,92],[11,96],[12,109],[13,103],[14,95],[15,107],[16,99],[17,98],[18,108],[19,93],[20,100],[21,94],[22,91]];
for($w=1;$w<=22;$w++) {
 $p->prepare('INSERT INTO inventory_warehouses(warehouse_id,company_id,code,name) VALUES(?,2,?,?)')->execute([$w,'SYNTHETIC-'.$w,'Synthetic shop '.$w]);
 $p->prepare("INSERT INTO data_external_ids(company_id,entity_type,entity_id,external_id) VALUES(2,'warehouses',?,?)")->execute([$w,sprintf('PBI-SHOP-%03d',$w)]);
}
foreach($pairs as [$w,$e]) {
 $p->prepare("INSERT INTO hr_employees(employee_id,company_id,employee_number,first_name,last_name,work_email,job_title,employment_type,hire_date) VALUES(?,2,?,'Synthetic','Manager',?,'Fixture manager','full_time','2026-01-01')")->execute([$e,'SYNTHETIC-'.$e,'synthetic-'.$e.'@example.test']);
 $p->prepare("INSERT INTO bi_powerbi_shop_manager_assignments(company_id,warehouse_id,employee_id,status,mapping_source) VALUES(2,?,?,'pending','SYNTHETIC_MIGRATION_FIXTURE')")->execute([$w,$e]);
}

$catalog=__DIR__.'/../database/migrations/mysql';
if(getenv('RECRUITMENT_TEST_BASELINE')==='110') {
 $catalog=sys_get_temp_dir().'/recruitment-baseline-110'; mkdir($catalog,0700,true);
 foreach(glob(__DIR__.'/../database/migrations/mysql/*.php') as $file) if((int)basename($file)<=110) copy($file,$catalog.'/'.basename($file));
}
$result=$runner->run($catalog);
echo json_encode($result)."\n";

} catch(Throwable $e) { do {fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);} while($e=$e->getPrevious()); exit(1); }
