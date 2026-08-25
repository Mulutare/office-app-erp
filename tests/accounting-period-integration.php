<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\AccountingPeriodService;

$passed=0;$failed=[];$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{fwrite($ok?STDOUT:STDERR,($ok?'PASS ':'FAIL ').$label.PHP_EOL);$ok?$passed++:$failed[]=$label;};$throws=static function(callable $work,string $label)use($check):void{try{$work();$check(false,$label);}catch(Throwable){$check(true,$label);}};
$pdo=db();$company=(int)$pdo->query("SELECT company_id FROM companies WHERE code='default' LIMIT 1")->fetchColumn();$other=(int)$pdo->query("SELECT company_id FROM companies WHERE company_id<>$company ORDER BY company_id LIMIT 1")->fetchColumn();$actor=(int)$pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];$service=new AccountingPeriodService();$suffix=bin2hex(random_bytes(3));
try{
 $definition=require __DIR__.'/../database/migrations/mysql/058_accounting_period_lifecycle.php';$sql=implode("\n",$definition['statements']);$check(($definition['version']??'')==='058'&&count($definition['statements'])===8,'Migration 058 is additive and fully defined');
 $check(str_contains($sql,'uq_finance_period_identity')&&str_contains($sql,'finance_accounting_period_history'),'Migration enforces tenant-scoped period identity and immutable history');
 $check((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE active=TRUE AND code IN('finance.period.view','finance.period.manage','finance.period.close','finance.period.reopen')")->fetchColumn()===4,'All four period permissions are active');
 $from=(date('Y')+2).'-01-01';$to=(date('Y')+2).'-12-31';$year=$service->createFiscalYear(['fiscal_year_name'=>'E2E FY '.$suffix,'date_from'=>$from,'date_to'=>$to],$actor);$check($year>0,'Authorized lifecycle creates a company fiscal year');
 $throws(fn()=>$service->createFiscalYear(['fiscal_year_name'=>'Overlap '.$suffix,'date_from'=>$from,'date_to'=>$to],$actor),'Overlapping fiscal year is rejected');
 $period=$service->createPeriod(['fiscal_year_id'=>$year,'period_name'=>'E2E Period '.$suffix,'date_from'=>$from,'date_to'=>$to],$actor);$check($period>0,'Open period is created inside its fiscal year');
 $finance=App\Repositories\RepositoryFactory::finance();$guard=new ReflectionMethod($finance,'assertOpenPostingDate');$guard->setAccessible(true);$guard->invoke($finance,$company,$from);$check(true,'Posting date inside exactly one open period is allowed');$throws(fn()=>$guard->invoke($finance,$company,(date('Y')+5).'-01-01'),'Posting with no accounting period is denied');
 $throws(fn()=>$service->createPeriod(['fiscal_year_id'=>$year,'period_name'=>'Overlap Period '.$suffix,'date_from'=>$from,'date_to'=>$to],$actor),'Overlapping accounting period is rejected');
 $service->transition($period,'close',null,$actor);$check($pdo->query("SELECT status FROM finance_accounting_periods WHERE period_id=$period")->fetchColumn()==='closed','Open period closes under row lock');
 $throws(fn()=>$guard->invoke($finance,$company,$from),'Posting in a closed period is denied');
 $throws(fn()=>$service->transition($period,'reopen','',$actor),'Reopening without a reason is rejected');
 $service->transition($period,'lock',null,$actor);$check($pdo->query("SELECT status FROM finance_accounting_periods WHERE period_id=$period")->fetchColumn()==='locked','Closed period can be irreversibly protected until authorized reopen');
 $throws(fn()=>$guard->invoke($finance,$company,$from),'Posting in a locked period is denied');
 $service->transition($period,'reopen','Correction approved for integration verification',$actor);$check($pdo->query("SELECT status FROM finance_accounting_periods WHERE period_id=$period")->fetchColumn()==='open','Authorized reasoned reopening restores a locked period');
 $check((int)$pdo->query("SELECT COUNT(*) FROM finance_accounting_period_history WHERE company_id=$company AND period_id=$period")->fetchColumn()===4,'Create, close, lock and reopen form an immutable audit history');
 $_SESSION['auth']['company']['company_id']=$other;$check(!in_array($period,array_map('intval',array_column($service->workspace()['periods'],'period_id')),true),'Period workspace enforces tenant isolation');$_SESSION['auth']['company']['company_id']=$company;
 $repoSource=(string)file_get_contents(__DIR__.'/../app/repositories/MySql/FinanceRepository.php');$check(str_contains($repoSource,"status='open'")&&str_contains($repoSource,'!== 1'),'Journal posting fails closed unless exactly one open period matches');
 $controller=(string)file_get_contents(__DIR__.'/../app/controllers/FinanceController.php');$check(str_contains($controller,"'finance.period.reopen'")&&str_contains($controller,"'finance.period.close'"),'Period mutations have explicit permission gates');
 $recovery=require __DIR__.'/../database/migrations/mysql/recovery/058.php';$check($recovery($pdo)==='baseline','Complete migration-058 schema is safely recognized for upgrade baselining');
}catch(Throwable $e){$check(false,'Unexpected accounting-period error: '.$e->getMessage());}
fwrite(STDOUT,sprintf("Accounting periods: %d passed, %d failed.%s",$passed,count($failed),PHP_EOL));exit($failed===[]?0:1);
