<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable database required.');
$checks=0;$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";};
$reject=static function($call){try{$call();return false;}catch(Throwable $e){return true;}};
$db=db();$directory=__DIR__.'/../database/migrations/mysql';$runner=new App\Database\MigrationRunner($db,'mysql');
$check($db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()==='110','Disposable baseline is exactly migration 110');
$migration=require $directory.'/111_recruitment_careers.php';
$check($migration['preflight']($db)==='apply','Clean 110 schema passes 111 preflight');
$company=(int)$db->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();
$repo=new App\Services\Recruitment\Repository($db,$company);
$service=new App\Services\Recruitment\RecruitmentService($repo,new App\Services\Recruitment\DocumentStore());
$legacy=$service->create(['name'=>'Pre-111 legacy applicant'],null,['name'=>'legacy.pdf','bytes'=>"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n"]);
$repo->update('applications',$legacy,['source'=>'email','needs_review'=>1]);
$legacyBefore=$repo->find('applications',$legacy);
$documentsBefore=$repo->all('attachments');
$before=(int)$db->query('SELECT COUNT(*) FROM recruitment_applications')->fetchColumn();
$result=$runner->runNext($directory,'111');
$check($result['result']==='applied'&&$result['version']==='111','Single-step 110 to 111 migration applies');
$check((int)$db->query('SELECT COUNT(*) FROM recruitment_applications')->fetchColumn()===$before,'Migration preserves existing applications');
$check($repo->find('applications',$legacy)===$legacyBefore&&$repo->all('attachments')===$documentsBefore,'Migration preserves actual legacy unmatched email record and quarantined document unchanged');
$check($reject(fn()=>$migration['preflight']($db)),'Existing objects fail preflight without ledger bypass');
$check(in_array('111',$runner->run($directory)['skipped'],true),'Ledger safely skips repeated 111');
$audit=$runner->auditAppliedMigrations($directory);
$check($audit['first_unapplied']===null&&end($audit['applied_versions'])==='111','Migration audit recognizes complete 111 ledger');
foreach(['vacancy_criteria','application_answers','screening_results','external_submissions','publication_state','publication_revisions'] as $table) {
 $s=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute(['recruitment_'.$table]);
 $check((int)$s->fetchColumn()===1,'Expected normalized table '.$table);
}
$columns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='recruitment_vacancies'")->fetchAll(PDO::FETCH_COLUMN);
$check(!array_diff(['public_slug','public_status','published_at','public_revision'],$columns),'Public vacancy metadata columns exist');
$s=$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='recruitment_external_submissions' AND index_name='uq_rec_external_source' AND non_unique=0");
$check((int)$s->fetchColumn()===3,'External source identity has composite database uniqueness');
$fks=$db->query("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name IN('recruitment_application_answers','recruitment_screening_results','recruitment_publication_revisions') AND column_name='company_id' AND referenced_column_name='company_id'")->fetchColumn();
$check((int)$fks>=6,'Tenant foreign keys cover answers results and revisions');
$check(!preg_match('/\b(?:DROP|TRUNCATE|DELETE)\b/i',implode("\n",$migration['statements'])),'Migration has no destructive SQL');
$check((int)$db->query("SELECT COUNT(*) FROM permissions WHERE code='recruitment.publish' AND module='recruitment'")->fetchColumn()===1,'Publication permission belongs to Recruitment');
echo "$checks careers migration checks passed\n";
